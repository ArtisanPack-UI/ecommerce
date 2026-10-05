<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Services\WebhookSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/../Api/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'requeues deliveries parked while a subscription was inactive (D17)', function (): void {
    $subscription = WebhookSubscription::factory()->create();
    $parked       = WebhookDelivery::factory()->create( [ 'subscription_id' => $subscription->id, 'attempts' => 2, 'next_retry_at' => null, 'delivered_at' => null ] );
    $exhausted    = WebhookDelivery::factory()->create( [ 'subscription_id' => $subscription->id, 'attempts' => 10, 'next_retry_at' => null, 'delivered_at' => null ] );
    $delivered    = WebhookDelivery::factory()->create( [ 'subscription_id' => $subscription->id, 'attempts' => 1, 'next_retry_at' => null, 'delivered_at' => Carbon::now() ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->postJson( "/api/ecommerce/v1/admin/webhook-subscriptions/{$subscription->id}/replay-parked", [], idem() )
        ->assertStatus( 202 )
        ->assertJsonPath( 'data.requeued', 1 );

    expect( $parked->refresh()->next_retry_at )->not->toBeNull()
        ->and( $exhausted->refresh()->next_retry_at )->toBeNull()
        ->and( $delivered->refresh()->next_retry_at )->toBeNull();
} );

it( 'does nothing for an inactive subscription', function (): void {
    $subscription = WebhookSubscription::factory()->inactive()->create();

    expect( app( WebhookSubscriptionService::class )->replayParked( $subscription ) )->toBe( 0 );
} );
