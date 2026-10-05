<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\OrderRefunded;
use ArtisanPackUI\Ecommerce\Jobs\DeliverWebhookJob;
use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Services\WebhookDeliveryService;
use ArtisanPackUI\Ecommerce\Services\WebhookDispatcher;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookPayloadFactory;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__ . '/../Api/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'never lets webhook fan-out roll back the change that fired the event', function (): void {
    $dispatcher = Mockery::mock( WebhookDispatcher::class );
    $dispatcher->shouldReceive( 'dispatch' )->andThrow( new RuntimeException( 'webhook tables missing' ) );
    app()->instance( WebhookDispatcher::class, $dispatcher );

    $order = Order::factory()->create();

    DB::transaction( function () use ( $order ): void {
        $refund = Refund::factory()->create( [ 'order_id' => $order->id ] );
        event( new OrderRefunded( $order, $refund ) );
    } );

    expect( Refund::query()->count() )->toBe( 1 );
} );

it( 'fans out only after the transaction commits', function (): void {
    Queue::fake();
    WebhookSubscription::factory()->events( [ '*' ] )->create();
    $order = Order::factory()->create();

    try {
        DB::transaction( function () use ( $order ): void {
            event( new OrderRefunded( $order, Refund::factory()->create( [ 'order_id' => $order->id ] ) ) );

            throw new RuntimeException( 'roll back' );
        } );
    } catch ( RuntimeException ) {
    }

    expect( WebhookDelivery::query()->count() )->toBe( 0 );
} );

it( 'judges which addresses are public', function ( string $address, bool $public ): void {
    expect( app( WebhookUrlGuard::class )->isPublic( $address ) )->toBe( $public );
} )->with( [
    'public v4'        => [ '93.184.216.34', true ],
    'public v6'        => [ '2606:2800:220:1:248:1893:25c8:1946', true ],
    'loopback'         => [ '127.0.0.1', false ],
    'rfc1918 10/8'     => [ '10.0.0.5', false ],
    'rfc1918 192.168'  => [ '192.168.1.10', false ],
    'metadata'         => [ '169.254.169.254', false ],
    'cgnat'            => [ '100.64.1.1', false ],
    'v6 loopback'      => [ '::1', false ],
    'v6 ula'           => [ 'fd00::1', false ],
    'v6 link-local'    => [ 'fe80::1', false ],
    'v4-mapped v6'     => [ '::ffff:10.0.0.1', false ],
    'nat64'            => [ '64:ff9b::a00:1', false ],
    '6to4'             => [ '2002:a00:1::1', false ],
] );

it( 'rejects subscriptions to hosts that resolve to private addresses', function (): void {
    WebhookUrlGuard::resolveUsing( fn ( string $host ): array => 'internal.example.test' === $host ? [ '10.0.0.5' ] : [ '93.184.216.34' ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    foreach ( [ 'https://internal.example.test/hook', 'https://127.0.0.1/hook', 'https://[::1]/hook' ] as $url ) {
        $this->postJson( '/api/ecommerce/v1/admin/webhook-subscriptions', [ 'name' => 'x', 'url' => $url, 'events' => [ '*' ] ], idem() )
            ->assertStatus( 422 )
            ->assertJsonPath( 'errors.0.field', 'url' );
    }

    config()->set( 'artisanpack.ecommerce.webhooks.allow_private_hosts', true );

    $this->postJson( '/api/ecommerce/v1/admin/webhook-subscriptions', [ 'name' => 'x', 'url' => 'https://internal.example.test/hook', 'events' => [ '*' ] ], idem() )
        ->assertCreated();
} );

it( 're-checks the endpoint at send time (DNS rebinding)', function (): void {
    Http::fake();
    $subscription = WebhookSubscription::factory()->create();
    WebhookUrlGuard::resolveUsing( fn (): array => [ '127.0.0.1' ] );

    $delivery = WebhookDelivery::factory()->create( [ 'subscription_id' => $subscription->id ] );

    expect( app( WebhookDeliveryService::class )->attempt( $delivery ) )->toBeFalse()
        ->and( $delivery->refresh()->attempts )->toBe( 1 )
        ->and( $delivery->response_body )->toContain( 'public address' );

    Http::assertNothingSent();
} );

it( 'skips a stale job whose claim was superseded', function (): void {
    Http::fake();
    Carbon::setTestNow( '2026-09-28 12:00:00' );

    $delivery = WebhookDelivery::factory()->create( [ 'next_retry_at' => Carbon::now()->addMinutes( 5 ) ] );

    ( new DeliverWebhookJob( $delivery->id, Carbon::now()->addMinutes( 1 )->toDateTimeString() ) )->handle( app( WebhookDeliveryService::class ) );
    Http::assertNothingSent();

    ( new DeliverWebhookJob( $delivery->id, Carbon::now()->addMinutes( 5 )->toDateTimeString() ) )->handle( app( WebhookDeliveryService::class ) );
    Http::assertSentCount( 1 );

    Carbon::setTestNow();
} );

it( 'refuses to replay deliveries of an inactive subscription', function (): void {
    $subscription = WebhookSubscription::factory()->inactive()->create();
    $delivery     = WebhookDelivery::factory()->create( [ 'subscription_id' => $subscription->id ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}/replay/{$delivery->id}", [], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/webhook-subscription-inactive' );
} );

it( 'keeps the signing secret out of the stored idempotent replay', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );
    $headers = [ 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json' ];
    $payload = [ 'name' => 'x', 'url' => 'https://hooks.example.test/a', 'events' => [ '*' ] ];

    $first = $this->postJson( '/api/ecommerce/v1/admin/webhook-subscriptions', $payload, $headers )->assertCreated();
    $again = $this->postJson( '/api/ecommerce/v1/admin/webhook-subscriptions', $payload, $headers )->assertCreated()->assertHeader( 'Idempotent-Replay', 'true' );

    expect( $first->json( 'data.secret' ) )->toStartWith( 'whsec_' )
        ->and( $again->json( 'data' ) )->not->toHaveKey( 'secret' )
        ->and( $again->json( 'data.id' ) )->toBe( $first->json( 'data.id' ) )
        ->and( (string) IdempotencyRecord::query()->value( 'response_body' ) )->not->toContain( $first->json( 'data.secret' ) );
} );

it( 'leaves admin-only fields out of webhook payloads unless configured', function (): void {
    $order = Order::factory()->create( [ 'ip_address' => '203.0.113.7' ] );

    expect( app( WebhookPayloadFactory::class )->serialize( $order ) )->not->toHaveKey( 'ip_address' );

    config()->set( 'artisanpack.ecommerce.webhooks.include_admin_fields', true );

    expect( app( WebhookPayloadFactory::class )->serialize( $order )['ip_address'] )->toBe( '203.0.113.7' );
} );
