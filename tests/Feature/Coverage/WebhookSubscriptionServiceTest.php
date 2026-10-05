<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Jobs\DeliverWebhookJob;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Services\WebhookSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses( RefreshDatabase::class );

afterEach( function (): void {
    removeAllFilters( 'ap.ecommerce.webhook.subscribing' );
} );

function covWebhookSubs(): WebhookSubscriptionService
{
    return app( WebhookSubscriptionService::class );
}

it( 'creates a subscription with a generated secret and de-duplicated events', function (): void {
    $subscription = covWebhookSubs()->create( [
        'name'   => 'ERP',
        'url'    => 'https://hooks.example.test/erp',
        'events' => [ 'order.refunded', 'order.refunded', 'order.placed' ],
    ] );

    expect( $subscription )->toBeInstanceOf( WebhookSubscription::class )
        ->and( $subscription->exists )->toBeTrue()
        ->and( $subscription->secret )->toStartWith( 'whsec_' )
        ->and( strlen( $subscription->secret ) )->toBe( 54 )
        ->and( $subscription->events )->toBe( [ 'order.refunded', 'order.placed' ] );
} );

it( 'keeps a secret the caller supplies, but not an empty one', function (): void {
    $given = covWebhookSubs()->create( [ 'name' => 'A', 'url' => 'https://hooks.example.test/a', 'events' => [ '*' ], 'secret' => 'my-own-secret' ] );
    $empty = covWebhookSubs()->create( [ 'name' => 'B', 'url' => 'https://hooks.example.test/b', 'events' => [ '*' ], 'secret' => '' ] );

    expect( $given->secret )->toBe( 'my-own-secret' )
        ->and( $empty->secret )->toStartWith( 'whsec_' );
} );

it( 'lets the subscribing filter change or veto a subscription', function (): void {
    addFilter( 'ap.ecommerce.webhook.subscribing', fn ( array $attributes ): ?array => str_contains( (string) $attributes['url'], 'blocked' ) ? null : [ ...$attributes, 'name' => 'Renamed' ] );

    $vetoed  = covWebhookSubs()->create( [ 'name' => 'X', 'url' => 'https://blocked.example.test/x', 'events' => [ '*' ] ] );
    $renamed = covWebhookSubs()->create( [ 'name' => 'Y', 'url' => 'https://hooks.example.test/y', 'events' => [ '*' ] ] );

    expect( $vetoed )->toBeNull()
        ->and( WebhookSubscription::query()->where( 'url', 'https://blocked.example.test/x' )->exists() )->toBeFalse()
        ->and( $renamed->name )->toBe( 'Renamed' );
} );

it( 'de-duplicates events on update and leaves them alone when not given', function (): void {
    $subscription = WebhookSubscription::factory()->create( [ 'events' => [ 'order.placed' ] ] );

    covWebhookSubs()->update( $subscription, [ 'events' => [ 'order.refunded', 'order.refunded' ] ] );
    expect( $subscription->fresh()->events )->toBe( [ 'order.refunded' ] );

    covWebhookSubs()->update( $subscription, [ 'name' => 'Renamed' ] );
    expect( $subscription->fresh()->events )->toBe( [ 'order.refunded' ] )
        ->and( $subscription->fresh()->name )->toBe( 'Renamed' );
} );

it( 'resets the failure streak only when a subscription is switched back on', function (): void {
    $off = WebhookSubscription::factory()->create( [ 'is_active' => false, 'consecutive_failures' => 9 ] );
    $on  = WebhookSubscription::factory()->create( [ 'is_active' => true, 'consecutive_failures' => 4 ] );

    covWebhookSubs()->update( $off, [ 'is_active' => true ] );
    covWebhookSubs()->update( $on, [ 'is_active' => true, 'name' => 'Still on' ] );

    expect( $off->fresh()->consecutive_failures )->toBe( 0 )
        ->and( $on->fresh()->consecutive_failures )->toBe( 4 );
} );

it( 'replays a delivery as a new queued delivery of the same event and payload', function (): void {
    Queue::fake();
    $subscription = WebhookSubscription::factory()->create();
    $original     = WebhookDelivery::factory()->create( [ 'subscription_id' => $subscription->id, 'delivered_at' => now(), 'next_retry_at' => null ] );

    $replay = covWebhookSubs()->replay( $original );

    expect( $replay )->toBeInstanceOf( WebhookDelivery::class )
        ->and( $replay->id )->not->toBe( $original->id )
        ->and( $replay->event )->toBe( $original->event )
        ->and( $replay->payload )->toBe( $original->payload )
        ->and( $replay->delivered_at )->toBeNull();

    Queue::assertPushed( DeliverWebhookJob::class, fn ( DeliverWebhookJob $job ): bool => $job->deliveryId === $replay->id );
} );

it( 'does not replay to an inactive subscription', function (): void {
    Queue::fake();
    $subscription = WebhookSubscription::factory()->create( [ 'is_active' => false ] );
    $original     = WebhookDelivery::factory()->create( [ 'subscription_id' => $subscription->id ] );

    expect( covWebhookSubs()->replay( $original ) )->toBeNull()
        ->and( WebhookDelivery::query()->count() )->toBe( 1 );

    Queue::assertNothingPushed();
} );

it( 'requeues nothing for an inactive subscription', function (): void {
    $subscription = WebhookSubscription::factory()->create( [ 'is_active' => false ] );
    WebhookDelivery::factory()->create( [ 'subscription_id' => $subscription->id, 'next_retry_at' => null, 'attempts' => 1 ] );

    expect( covWebhookSubs()->replayParked( $subscription ) )->toBe( 0 );
} );
