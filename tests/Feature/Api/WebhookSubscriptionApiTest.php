<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Jobs\DeliverWebhookJob;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'is admin-only', function (): void {
    $this->getJson( '/api/ecommerce/v1/admin/webhook-subscriptions' )->assertUnauthorized();
    $this->actingAs( ecommerceShopper(), 'sanctum' )->getJson( '/api/ecommerce/v1/admin/webhook-subscriptions' )->assertForbidden();
} );

it( 'creates a subscription and reveals the secret only once', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $response = $this->postJson( '/api/ecommerce/v1/admin/webhook-subscriptions', [
        'name'   => 'Zapier',
        'url'    => 'https://hooks.zapier.test/abc',
        'events' => [ 'order.refunded', 'order.refunded', 'payment.succeeded' ],
    ], idem() )->assertCreated()
        ->assertJsonPath( 'data.type', 'webhookSubscription' )
        ->assertJsonPath( 'data.events', [ 'order.refunded', 'payment.succeeded' ] )
        ->assertJsonPath( 'data.is_active', true );

    $secret = $response->json( 'data.secret' );

    expect( $secret )->toStartWith( 'whsec_' )
        ->and( WebhookSubscription::query()->sole()->secret )->toBe( $secret );

    $this->getJson( '/api/ecommerce/v1/admin/webhook-subscriptions' )
        ->assertOk()
        ->assertJsonCount( 1, 'data' )
        ->assertJsonMissingPath( 'data.0.secret' );
} );

it( 'validates the endpoint and event names', function ( array $payload, string $field ): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( '/api/ecommerce/v1/admin/webhook-subscriptions', $payload + [
            'name'   => 'Hook',
            'url'    => 'https://hooks.example.test/x',
            'events' => [ 'order.refunded' ],
        ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', $field );
} )->with( [
    'plain http'      => [ [ 'url' => 'http://hooks.example.test/x' ], 'url' ],
    'not a url'       => [ [ 'url' => 'not a url' ], 'url' ],
    'no events'       => [ [ 'events' => [] ], 'events' ],
    'bad event name'  => [ [ 'events' => [ 'Order Refunded' ] ], 'events.0' ],
    'short secret'    => [ [ 'secret' => 'short' ], 'secret' ],
] );

it( 'accepts http endpoints only when insecure URLs are allowed', function (): void {
    config()->set( 'artisanpack.ecommerce.webhooks.allow_insecure_urls', true );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( '/api/ecommerce/v1/admin/webhook-subscriptions', [
            'name'   => 'Local',
            'url'    => 'http://localhost:8080/hook',
            'events' => [ '*' ],
        ], idem() )
        ->assertCreated();
} );

it( 'lets the subscribing filter abort creation', function (): void {
    addFilter( 'ap.ecommerce.webhook.subscribing', fn (): mixed => null );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( '/api/ecommerce/v1/admin/webhook-subscriptions', [
            'name'   => 'Blocked',
            'url'    => 'https://hooks.example.test/x',
            'events' => [ 'order.refunded' ],
        ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/webhook-subscription-rejected' );

    expect( WebhookSubscription::query()->count() )->toBe( 0 );
} );

it( 'resets the failure streak when an operator re-enables a subscription', function (): void {
    $subscription = WebhookSubscription::factory()->inactive()->create( [ 'consecutive_failures' => 10 ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}", [ 'is_active' => true ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.is_active', true )
        ->assertJsonPath( 'data.consecutive_failures', 0 )
        ->assertJsonMissingPath( 'data.secret' );
} );

it( 'includes recent deliveries on request', function (): void {
    $subscription = WebhookSubscription::factory()->create();
    WebhookDelivery::factory()->count( 25 )->create( [ 'subscription_id' => $subscription->id ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( '/api/ecommerce/v1/admin/webhook-subscriptions?include=deliveries' )
        ->assertOk()
        ->assertJsonCount( 20, 'data.0.deliveries' );
} );

it( 'deletes a subscription with its deliveries', function (): void {
    $subscription = WebhookSubscription::factory()->create();
    WebhookDelivery::factory()->create( [ 'subscription_id' => $subscription->id ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->deleteJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}", [], idem() )
        ->assertOk();

    expect( WebhookSubscription::query()->count() )->toBe( 0 )
        ->and( WebhookDelivery::query()->count() )->toBe( 0 );
} );

it( 'replays a delivery as a new ledger row', function (): void {
    Queue::fake();
    $subscription = WebhookSubscription::factory()->create();
    $original     = WebhookDelivery::factory()->create( [ 'subscription_id' => $subscription->id, 'next_retry_at' => null ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}/replay/{$original->id}", [], idem() )
        ->assertStatus( 202 )
        ->assertJsonPath( 'data.event', $original->event )
        ->assertJsonPath( 'data.payload_hash', $original->payload_hash );

    expect( WebhookDelivery::query()->count() )->toBe( 2 );
    Queue::assertPushed( DeliverWebhookJob::class, 1 );
} );

it( 'refuses to replay another subscription\'s delivery', function (): void {
    $subscription = WebhookSubscription::factory()->create();
    $foreign      = WebhookDelivery::factory()->create();

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}/replay/{$foreign->id}", [], idem() )
        ->assertNotFound();
} );

it( 'lists a subscription\'s deliveries, cursor-paginated, without payloads', function (): void {
    $subscription = WebhookSubscription::factory()->create();
    WebhookDelivery::factory()->count( 3 )->create( [ 'subscription_id' => $subscription->id, 'response_body' => 'nope' ] );
    WebhookDelivery::factory()->create();

    $response = $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}/deliveries?per_page=2" )
        ->assertOk()
        ->assertJsonCount( 2, 'data' )
        ->assertJsonPath( 'data.0.type', 'webhookDelivery' )
        ->assertJsonMissingPath( 'data.0.payload' )
        ->assertJsonMissingPath( 'data.0.response_body' );

    $next = $response->json( 'meta.next_cursor' );

    expect( $next )->not->toBeNull();

    $this->getJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}/deliveries?per_page=2&cursor={$next}" )
        ->assertOk()
        ->assertJsonCount( 1, 'data' );
} );

it( 'filters deliveries by event and delivery status', function (): void {
    $subscription = WebhookSubscription::factory()->create();
    $base         = [ 'subscription_id' => $subscription->id ];

    $delivered = WebhookDelivery::factory()->create( $base + [ 'delivered_at' => now(), 'next_retry_at' => null, 'attempts' => 1, 'response_status' => 200 ] );
    $retrying  = WebhookDelivery::factory()->create( $base + [ 'next_retry_at' => now()->addMinute(), 'attempts' => 2, 'response_status' => 500 ] );
    $failed    = WebhookDelivery::factory()->create( $base + [ 'next_retry_at' => null, 'attempts' => 8, 'response_status' => 500, 'event' => 'order.placed' ] );
    $pending   = WebhookDelivery::factory()->create( $base + [ 'attempts' => 0 ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $ids = fn ( string $query ): array => array_column( $this->getJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}/deliveries?{$query}" )->assertOk()->json( 'data' ), 'id' );

    expect( $ids( 'filter[status]=delivered' ) )->toBe( [ $delivered->id ] )
        ->and( $ids( 'filter[status]=retrying' ) )->toBe( [ $retrying->id ] )
        ->and( $ids( 'filter[status]=failed' ) )->toBe( [ $failed->id ] )
        ->and( $ids( 'filter[status]=pending' ) )->toBe( [ $pending->id ] )
        ->and( $ids( 'filter[status]=bogus' ) )->toBe( [] )
        ->and( $ids( 'filter[event]=order.placed' ) )->toBe( [ $failed->id ] );
} );

it( 'shows one delivery with its payload and response body', function (): void {
    $subscription = WebhookSubscription::factory()->create();
    $delivery     = WebhookDelivery::factory()->create( [ 'subscription_id' => $subscription->id, 'response_status' => 500, 'response_body' => 'Server error' ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}/deliveries/{$delivery->id}" )
        ->assertOk()
        ->assertJsonPath( 'data.id', $delivery->id )
        ->assertJsonPath( 'data.payload.event', 'order.refunded' )
        ->assertJsonPath( 'data.response_body', 'Server error' );
} );

it( 'scopes a delivery read to its subscription and gates it on webhookSubscription.viewAny', function (): void {
    $subscription = WebhookSubscription::factory()->create();
    $foreign      = WebhookDelivery::factory()->create();

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}/deliveries/{$foreign->id}" )
        ->assertNotFound();

    $this->actingAs( ecommerceShopper(), 'sanctum' );

    $this->getJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}/deliveries" )->assertForbidden();
    $this->getJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$foreign->subscription_id}/deliveries/{$foreign->id}" )->assertForbidden();

    Gate::define( 'ecommerce.webhookSubscription.viewAny', fn (): bool => true );

    $this->getJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}/deliveries" )->assertOk();
} );

it( 'does not load payloads or response bodies for the deliveries listing', function (): void {
    $subscription = WebhookSubscription::factory()->create();
    WebhookDelivery::factory()->create( [ 'subscription_id' => $subscription->id, 'response_body' => str_repeat( 'x', 5000 ) ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    DB::enableQueryLog();

    $this->getJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}/deliveries" )
        ->assertOk()
        ->assertJsonPath( 'data.0.event', 'order.refunded' );

    $sql = collect( DB::getQueryLog() )->pluck( 'query' )->first( fn ( string $query ): bool => str_contains( $query, 'from "ecommerce_webhook_deliveries"' ) );

    expect( $sql )->not->toBeNull()
        ->and( $sql )->not->toContain( '"payload",' )
        ->and( $sql )->not->toContain( 'response_body' )
        ->and( $sql )->not->toContain( '*' );
} );
