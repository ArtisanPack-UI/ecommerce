<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\PromotionUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'treats reusing an Idempotency-Key on a different resource as a conflict', function (): void {
    [ $first, $second ] = Order::factory()->count( 2 )->create()->all();
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $headers = idem();

    $this->patchJson( "/api/ecommerce/v1/orders/{$first->id}", [ 'customer_note' => 'X' ], $headers )->assertOk();
    $this->patchJson( "/api/ecommerce/v1/orders/{$second->id}", [ 'customer_note' => 'X' ], $headers )->assertStatus( 409 );

    expect( $second->fresh()->customer_note )->not->toBe( 'X' );
} );

it( 'merges order meta and refuses to let clients touch the fraud decision', function (): void {
    $order = Order::factory()->create( [ 'meta' => [ 'fraud_decision' => [ 'verdict' => 'block' ], 'source' => 'pos' ] ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->patchJson( "/api/ecommerce/v1/orders/{$order->id}", [ 'meta' => [ 'gift' => true ] ], idem() )->assertOk();

    expect( $order->fresh()->meta )->toMatchArray( [ 'fraud_decision' => [ 'verdict' => 'block' ], 'source' => 'pos', 'gift' => true ] );

    $this->patchJson( "/api/ecommerce/v1/orders/{$order->id}", [ 'meta' => [ 'fraud_decision' => [ 'verdict' => 'approve' ] ] ], idem() )
        ->assertStatus( 422 );
} );

it( 'coerces boolean and integer filters and rejects bad values', function (): void {
    Promotion::factory()->create( [ 'is_active' => true ] );
    Promotion::factory()->create( [ 'is_active' => false ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->getJson( '/api/ecommerce/v1/admin/promotions?filter[is_active]=true' )->assertJsonCount( 1, 'data' )->assertJsonPath( 'data.0.is_active', true );
    $this->getJson( '/api/ecommerce/v1/admin/promotions?filter[is_active]=false' )->assertJsonCount( 1, 'data' )->assertJsonPath( 'data.0.is_active', false );
    $this->getJson( '/api/ecommerce/v1/admin/promotions?filter[is_active]=maybe' )->assertStatus( 400 );
    $this->getJson( '/api/ecommerce/v1/orders?filter[customer_id]=abc' )->assertStatus( 400 );
} );

it( 'shows only current prices publicly and hides product meta', function (): void {
    $product = Product::factory()->create( [ 'meta' => [ 'supplier' => 'secret' ] ] );
    $product->prices()->create( [ 'currency' => 'USD', 'price_amount' => 1_000 ] );
    $product->prices()->create( [ 'currency' => 'USD', 'price_amount' => 700, 'starts_at' => Carbon::now()->addMonth() ] );
    $product->prices()->create( [ 'currency' => 'USD', 'price_amount' => 900, 'ends_at' => Carbon::now()->subDay() ] );

    $response = $this->getJson( "/api/ecommerce/v1/products/{$product->id}?include=prices" )->assertOk();

    expect( $response->json( 'data.prices.*.price.amount' ) )->toBe( [ 1_000 ] );
    expect( $response->json( 'data' ) )->not->toHaveKey( 'meta' );
} );

it( 'escapes LIKE wildcards in product search', function (): void {
    Product::factory()->create( [ 'name' => '50% off print' ] );
    Product::factory()->create( [ 'name' => '500 prints' ] );

    $this->getJson( '/api/ecommerce/v1/products?filter[search]=' . urlencode( '50%' ) )
        ->assertJsonCount( 1, 'data' )
        ->assertJsonPath( 'data.0.name', '50% off print' );
} );

it( 'answers 403 for every id when the ability is missing, so ids cannot be probed', function (): void {
    $order = Order::factory()->create();
    $this->actingAs( ecommerceShopper(), 'sanctum' );

    $this->getJson( "/api/ecommerce/v1/orders/{$order->id}" )->assertForbidden();
    $this->getJson( '/api/ecommerce/v1/orders/999999' )->assertForbidden();
} );

it( 'answers unauthenticated requests with a problem+json 401, even without an Accept header', function (): void {
    $this->get( '/api/ecommerce/v1/orders' )
        ->assertUnauthorized()
        ->assertHeader( 'Content-Type', 'application/problem+json' );
} );

it( 'clears shipment tracking fields sent as null', function (): void {
    $order = Order::factory()->create();
    ArtisanPackUI\Ecommerce\Models\OrderItem::factory()->create( [ 'order_id' => $order->id ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $id = $this->postJson( "/api/ecommerce/v1/orders/{$order->id}/shipments", [ 'method_key' => 'flat-rate', 'tracking_number' => '1Z' ], idem() )->json( 'data.id' );

    $this->patchJson( "/api/ecommerce/v1/orders/{$order->id}/shipments/{$id}", [ 'tracking_number' => null ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.tracking_number', null );
} );

it( 'refuses to delete a promotion that has been used on orders', function (): void {
    $promotion = Promotion::factory()->create();
    PromotionUsage::factory()->create( [ 'promotion_id' => $promotion->id ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->deleteJson( "/api/ecommerce/v1/admin/promotions/{$promotion->id}", [], idem() )->assertStatus( 409 );

    expect( Promotion::query()->whereKey( $promotion->id )->exists() )->toBeTrue();
} );
