<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderNote;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->order = Order::factory()->create( [ 'payment_status' => 'paid', 'total_amount' => 2_500 ] );
} );

it( 'cancels an order and reports what is still owed', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/orders/{$this->order->id}/cancel", [ 'reason' => 'Customer asked' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.type', 'order' )
        ->assertJsonPath( 'data.system_status', 'cancelled' )
        ->assertJsonPath( 'meta.cancellation.payment_voided', false )
        ->assertJsonPath( 'meta.cancellation.refund_owed', 2_500 )
        ->assertJsonPath( 'meta.cancellation.released', [] );

    expect( $this->order->fresh()->system_status )->toBe( 'cancelled' );
} );

it( 'reports an order that cannot be cancelled as problem+json', function (): void {
    $this->order->update( [ 'system_status' => 'complete' ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/orders/{$this->order->id}/cancel", [ 'reason' => 'Too late' ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/order-not-cancellable' );
} );

it( 'requires a reason and an idempotency key to cancel', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/orders/{$this->order->id}/cancel", [], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'reason' );

    $this->postJson( "/api/ecommerce/v1/orders/{$this->order->id}/cancel", [ 'reason' => 'x' ], [ 'Accept' => 'application/json' ] )
        ->assertStatus( 400 );
} );

it( 'requires the order cancel ability', function (): void {
    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/orders/{$this->order->id}/cancel", [ 'reason' => 'x' ], idem() )
        ->assertForbidden();
} );

it( 'adds a note to an order', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/orders/{$this->order->id}/notes", [ 'body' => 'Gift wrap', 'is_customer_visible' => true ], idem() )
        ->assertCreated()
        ->assertJsonPath( 'data.type', 'orderNote' )
        ->assertJsonPath( 'data.body', 'Gift wrap' )
        ->assertJsonPath( 'data.is_customer_visible', true )
        ->assertJsonPath( 'data.author_user_id', 1 );

    expect( OrderNote::query()->count() )->toBe( 1 );
} );

it( 'validates notes and requires the order update ability', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/orders/{$this->order->id}/notes", [ 'body' => '' ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'body' );

    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/orders/{$this->order->id}/notes", [ 'body' => 'x' ], idem() )
        ->assertForbidden();
} );

it( 'lists the order timeline newest first and filters by event type', function (): void {
    OrderTimelineEntry::factory()->create( [ 'order_id' => $this->order->id, 'event_type' => 'order.placed' ] );
    OrderTimelineEntry::factory()->create( [ 'order_id' => $this->order->id, 'event_type' => 'note.added' ] );
    OrderTimelineEntry::factory()->create( [ 'event_type' => 'order.placed' ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( "/api/ecommerce/v1/orders/{$this->order->id}/timeline" )
        ->assertOk()
        ->assertJsonCount( 2, 'data' )
        ->assertJsonPath( 'data.0.event_type', 'note.added' )
        ->assertJsonPath( 'data.1.event_type', 'order.placed' );

    $this->getJson( "/api/ecommerce/v1/orders/{$this->order->id}/timeline?filter[event_type]=order.placed" )
        ->assertOk()
        ->assertJsonCount( 1, 'data' );

    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->getJson( "/api/ecommerce/v1/orders/{$this->order->id}/timeline" )
        ->assertForbidden();
} );
