<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\OrderRefunded;
use ArtisanPackUI\Ecommerce\Events\WebhookDelivered;
use ArtisanPackUI\Ecommerce\Events\WebhookFailed;
use ArtisanPackUI\Ecommerce\Events\WebhookSubscriptionDisabled;
use ArtisanPackUI\Ecommerce\Jobs\DeliverWebhookJob;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Services\WebhookDeliveryService;
use ArtisanPackUI\Ecommerce\Services\WebhookDispatcher;
use ArtisanPackUI\Ecommerce\Support\RequestContext;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Carbon::setTestNow( '2026-09-28 12:00:00' );
} );

afterEach( function (): void {
    Carbon::setTestNow();
} );

it( 'fans an event out to every active subscription that listens for it', function (): void {
    Queue::fake();

    $listening = WebhookSubscription::factory()->events( [ 'order.refunded' ] )->create();
    $wildcard  = WebhookSubscription::factory()->events( [ '*' ] )->create();
    WebhookSubscription::factory()->events( [ 'order.status.changed' ] )->create();
    WebhookSubscription::factory()->events( [ 'order.refunded' ] )->inactive()->create();

    $deliveries = app( WebhookDispatcher::class )->dispatch( 'order.refunded', [ 'order' => [ 'id' => 1 ] ] );

    expect( $deliveries->pluck( 'subscription_id' )->all() )->toEqualCanonicalizing( [ $listening->id, $wildcard->id ] );

    $delivery = $deliveries->first();

    expect( $delivery->payload['event'] )->toBe( 'order.refunded' )
        ->and( $delivery->payload['data'] )->toBe( [ 'order' => [ 'id' => 1 ] ] )
        ->and( $delivery->payload_hash )->toBe( hash( 'sha256', (string) json_encode( $delivery->payload, WebhookDispatcher::JSON_FLAGS ) ) )
        ->and( $delivery->next_retry_at->greaterThan( Carbon::now() ) )->toBeTrue();

    Queue::assertPushed( DeliverWebhookJob::class, 2 );
} );

it( 'delivers a signed payload and records success', function (): void {
    Event::fake( [ WebhookDelivered::class ] );
    Http::fake( [ 'hooks.example.test/*' => Http::response( 'ok', 200 ) ] );

    $subscription = WebhookSubscription::factory()->create( [ 'consecutive_failures' => 3 ] );
    $delivery     = app( WebhookDispatcher::class )->dispatch( 'order.refunded', [ 'x' => 1 ] )->first();

    Http::assertSent( function ( ClientRequest $request ) use ( $subscription, $delivery ): bool {
        return $request->url() === $subscription->url
            && WebhookSigner::verify( $request->header( WebhookSigner::HEADER )[0], $request->body(), $subscription->secret, now: Carbon::now()->getTimestamp() )
            && 'order.refunded' === $request->header( 'X-ArtisanPack-Event' )[0]
            && (string) $delivery->id === $request->header( 'X-ArtisanPack-Delivery' )[0]
            && '' !== $request->header( 'X-Request-Id' )[0];
    } );

    $delivery->refresh();
    $subscription->refresh();

    expect( $delivery->delivered_at )->not->toBeNull()
        ->and( $delivery->next_retry_at )->toBeNull()
        ->and( $delivery->attempts )->toBe( 1 )
        ->and( $delivery->response_status )->toBe( 200 )
        ->and( $subscription->consecutive_failures )->toBe( 0 )
        ->and( $subscription->last_success_at )->not->toBeNull();

    Event::assertDispatched( WebhookDelivered::class );
} );

it( 'schedules a backoff retry on a non-2xx response', function (): void {
    Event::fake( [ WebhookFailed::class ] );
    Http::fake( [ '*' => Http::response( 'nope', 500 ) ] );

    $subscription = WebhookSubscription::factory()->create();
    $delivery     = app( WebhookDispatcher::class )->dispatch( 'order.refunded', [] )->first()->refresh();

    expect( $delivery->delivered_at )->toBeNull()
        ->and( $delivery->attempts )->toBe( 1 )
        ->and( $delivery->response_status )->toBe( 500 )
        ->and( $delivery->response_body )->toBe( 'nope' )
        ->and( $delivery->next_retry_at->equalTo( Carbon::now()->addMinute() ) )->toBeTrue()
        ->and( $subscription->refresh()->consecutive_failures )->toBe( 1 )
        ->and( $subscription->last_failure_at )->not->toBeNull();

    Event::assertDispatched( WebhookFailed::class, fn ( WebhookFailed $event ): bool => $event->delivery->is( $delivery ) );
} );

it( 'records connection failures without a status', function (): void {
    Http::fake( fn () => throw new ConnectionException( 'Connection refused' ) );

    $subscription = WebhookSubscription::factory()->create();
    $delivery     = app( WebhookDispatcher::class )->dispatch( 'order.refunded', [] )->first()->refresh();

    expect( $delivery->response_status )->toBeNull()
        ->and( $delivery->response_body )->toContain( 'Connection refused' )
        ->and( $delivery->next_retry_at )->not->toBeNull()
        ->and( $subscription->refresh()->consecutive_failures )->toBe( 1 );
} );

it( 'walks the backoff schedule and stops after the last attempt', function (): void {
    $service = app( WebhookDeliveryService::class );

    expect( $service->backoffAfter( 1 ) )->toBe( 60 )
        ->and( $service->backoffAfter( 5 ) )->toBe( 3_600 )
        ->and( $service->backoffAfter( 9 ) )->toBe( 43_200 )
        ->and( $service->backoffAfter( 10 ) )->toBeNull();

    config()->set( 'artisanpack.ecommerce.webhooks.max_attempts', 12 );

    expect( $service->backoffAfter( 10 ) )->toBe( 86_400 )
        ->and( $service->backoffAfter( 11 ) )->toBe( 86_400 )
        ->and( $service->backoffAfter( 12 ) )->toBeNull();

    config()->set( 'artisanpack.ecommerce.webhooks.max_attempts', 10 );

    Http::fake( [ '*' => Http::response( '', 503 ) ] );
    config()->set( 'artisanpack.ecommerce.webhooks.disable_after_failures', 100 );

    $delivery = WebhookDelivery::factory()->create( [ 'attempts' => 9 ] );
    $service->attempt( $delivery );

    expect( $delivery->refresh()->attempts )->toBe( 10 )
        ->and( $delivery->next_retry_at )->toBeNull();
} );

it( 'disables a subscription after the consecutive-failure ceiling', function (): void {
    Event::fake( [ WebhookSubscriptionDisabled::class, WebhookFailed::class ] );
    Http::fake( [ '*' => Http::response( '', 500 ) ] );
    config()->set( 'artisanpack.ecommerce.webhooks.disable_after_failures', 3 );

    $disabled = null;
    addAction( 'ap.ecommerce.webhook.subscriptionDisabled', function ( WebhookSubscription $subscription ) use ( &$disabled ): void {
        $disabled = $subscription;
    } );

    $subscription = WebhookSubscription::factory()->create();
    $dispatcher   = app( WebhookDispatcher::class );

    $dispatcher->dispatch( 'order.refunded', [ 'n' => 1 ] );
    $dispatcher->dispatch( 'order.refunded', [ 'n' => 2 ] );

    expect( $subscription->refresh()->is_active )->toBeTrue();

    $dispatcher->dispatch( 'order.refunded', [ 'n' => 3 ] );

    expect( $subscription->refresh()->is_active )->toBeFalse()
        ->and( $subscription->consecutive_failures )->toBe( 3 )
        ->and( $disabled?->is( $subscription ) )->toBeTrue();

    Event::assertDispatchedTimes( WebhookSubscriptionDisabled::class, 1 );
} );

it( 'resets the failure streak on success', function (): void {
    Http::fake( [ '*' => Http::sequence()->push( '', 500 )->push( 'ok', 200 ) ] );

    $subscription = WebhookSubscription::factory()->create();
    $delivery     = app( WebhookDispatcher::class )->dispatch( 'order.refunded', [] )->first();

    expect( $subscription->refresh()->consecutive_failures )->toBe( 1 );

    app( WebhookDeliveryService::class )->attempt( $delivery );

    expect( $subscription->refresh()->consecutive_failures )->toBe( 0 )
        ->and( $delivery->refresh()->attempts )->toBe( 2 )
        ->and( $delivery->delivered_at )->not->toBeNull();
} );

it( 'skips deliveries for inactive subscriptions and stops retrying them', function (): void {
    Http::fake();

    $delivery = WebhookDelivery::factory()->create( [
        'subscription_id' => WebhookSubscription::factory()->inactive()->create()->id,
    ] );

    expect( app( WebhookDeliveryService::class )->attempt( $delivery ) )->toBeFalse()
        ->and( $delivery->refresh()->next_retry_at )->toBeNull()
        ->and( $delivery->attempts )->toBe( 0 );

    Http::assertNothingSent();
} );

it( 'lets the delivering filter reshape the body before it is signed', function (): void {
    Http::fake( [ '*' => Http::response( 'ok' ) ] );
    addFilter( 'ap.ecommerce.webhook.delivering', function ( array $payload, WebhookSubscription $subscription, string $event ): array {
        $payload['store'] = 'demo';

        return $payload;
    } );

    $subscription = WebhookSubscription::factory()->create();
    app( WebhookDispatcher::class )->dispatch( 'order.refunded', [] );

    Http::assertSent( fn ( ClientRequest $request ): bool => 'demo' === $request['store']
        && WebhookSigner::verify( $request->header( WebhookSigner::HEADER )[0], $request->body(), $subscription->secret, now: Carbon::now()->getTimestamp() ) );
} );

it( 'does not follow redirects', function (): void {
    Http::fake( [ '*' => Http::response( '', 302, [ 'Location' => 'https://elsewhere.test/' ] ) ] );

    WebhookSubscription::factory()->create();
    $delivery = app( WebhookDispatcher::class )->dispatch( 'order.refunded', [] )->first()->refresh();

    expect( $delivery->delivered_at )->toBeNull()
        ->and( $delivery->response_status )->toBe( 302 );
    Http::assertSentCount( 1 );
} );

it( 'turns domain events into webhook deliveries', function (): void {
    Queue::fake();
    WebhookSubscription::factory()->events( [ 'order.refunded' ] )->create();

    $order  = Order::factory()->create();
    $refund = Refund::factory()->create( [ 'order_id' => $order->id, 'amount' => 500 ] );

    event( new OrderRefunded( $order, $refund ) );

    $delivery = WebhookDelivery::query()->sole();

    expect( $delivery->event )->toBe( 'order.refunded' )
        ->and( $delivery->payload['data']['order']['id'] )->toBe( $order->id )
        ->and( $delivery->payload['data']['order']['type'] )->toBe( 'order' )
        ->and( $delivery->payload['data']['refund']['amount'] )->toBe( [ 'amount' => 500, 'currency' => $refund->currency ] );
} );

it( 'hides and encrypts the subscription secret', function (): void {
    $subscription = WebhookSubscription::factory()->create( [ 'secret' => 'plain-text-secret-value' ] );

    expect( $subscription->toArray() )->not->toHaveKey( 'secret' )
        ->and( WebhookSubscription::query()->toBase()->value( 'secret' ) )->not->toBe( 'plain-text-secret-value' )
        ->and( $subscription->fresh()->secret )->toBe( 'plain-text-secret-value' );
} );

it( 'gives each queued delivery its own request id in a long-lived worker', function (): void {
    Http::fake( [ 'hooks.example.test/*' => Http::response( 'ok', 200 ) ] );
    RequestContext::reset();

    WebhookSubscription::factory()->create();

    app( WebhookDispatcher::class )->dispatch( 'order.refunded', [ 'x' => 1 ] );
    app( WebhookDispatcher::class )->dispatch( 'order.refunded', [ 'x' => 2 ] );

    $ids = Http::recorded()->map( fn ( array $pair ): string => $pair[0]->header( 'X-Request-Id' )[0] )->all();

    expect( $ids )->toHaveCount( 2 )
        ->and( $ids[0] )->not->toBe( $ids[1] )
        ->and( RequestContext::requestId() )->toBeNull();
} );
