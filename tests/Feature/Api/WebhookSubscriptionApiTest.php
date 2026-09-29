<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Jobs\DeliverWebhookJob;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
