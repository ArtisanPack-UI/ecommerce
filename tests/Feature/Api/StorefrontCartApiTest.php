<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\PromotionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

/**
 * An active product priced at `$amount` USD.
 */
function pricedProduct( int $amount = 2_500, array $attributes = [] ): Product
{
    $product = Product::factory()->create( $attributes );
    ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => $amount ] );

    return $product;
}

/**
 * A coupon worth `$percent`% off the cart.
 */
function percentCoupon( string $code = 'SAVE10', int $percent = 10 ): Coupon
{
    $promotion = Promotion::factory()->coupon()->create();
    PromotionAction::factory()->create( [ 'promotion_id' => $promotion->id, 'type' => 'percent-off-cart', 'config' => [ 'percent' => $percent ] ] );

    return Coupon::factory()->create( [ 'promotion_id' => $promotion->id, 'code' => $code ] );
}

function newCartToken( $test ): string
{
    return $test->postJson( '/api/ecommerce/v1/carts', [ 'currency' => 'usd' ], idem() )->assertCreated()->json( 'data.token' );
}

it( 'creates a cart (Idempotency-Key required) and reads it back', function (): void {
    $this->postJson( '/api/ecommerce/v1/carts', [] )->assertStatus( 400 );

    $token = newCartToken( $this );

    expect( strlen( $token ) )->toBe( 40 );

    $this->getJson( "/api/ecommerce/v1/carts/{$token}" )
        ->assertOk()
        ->assertJsonPath( 'data.currency', 'USD' )
        ->assertJsonPath( 'data.total.amount', 0 );
} );

it( 'prices added items server-side and keeps totals current', function (): void {
    $product = pricedProduct( 2_500 );
    $token   = newCartToken( $this );

    $this->postJson( "/api/ecommerce/v1/carts/{$token}/items", [
        'product_id'        => $product->id,
        'quantity'          => 2,
        'unit_price_amount' => 1,
    ], idem() )->assertCreated()
        ->assertJsonPath( 'data.items.0.unit_price.amount', 2_500 )
        ->assertJsonPath( 'data.items.0.quantity', 2 )
        ->assertJsonPath( 'data.subtotal.amount', 5_000 )
        ->assertJsonPath( 'data.total.amount', 5_000 );

    $item = CartItem::query()->sole();

    $this->patchJson( "/api/ecommerce/v1/carts/{$token}/items/{$item->id}", [ 'quantity' => 3 ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.subtotal.amount', 7_500 );

    $this->patchJson( "/api/ecommerce/v1/carts/{$token}/items/{$item->id}", [ 'quantity' => 0 ], idem() )
        ->assertOk()
        ->assertJsonCount( 0, 'data.items' )
        ->assertJsonPath( 'data.total.amount', 0 );
} );

it( 'prices a variant from its own price row', function (): void {
    $product = pricedProduct( 2_500 );
    $variant = ProductVariant::factory()->create( [ 'product_id' => $product->id ] );
    ProductPrice::factory()->forPriceable( $variant )->create( [ 'currency' => 'USD', 'price_amount' => 3_000 ] );
    $token = newCartToken( $this );

    $this->postJson( "/api/ecommerce/v1/carts/{$token}/items", [ 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1 ], idem() )
        ->assertCreated()
        ->assertJsonPath( 'data.items.0.product_variant_id', $variant->id )
        ->assertJsonPath( 'data.items.0.unit_price.amount', 3_000 );
} );

it( 'rejects items the storefront cannot sell', function ( array $payload, string $code ): void {
    $token = newCartToken( $this );

    $this->postJson( "/api/ecommerce/v1/carts/{$token}/items", $payload, idem() )
        ->assertStatus( 422 )
        ->assertHeader( 'Content-Type', 'application/problem+json' )
        ->assertJsonPath( 'errors.0.code', $code );
} )->with( [
    'unknown product' => [ fn () => [ 'product_id' => 999, 'quantity' => 1 ], 'product-unavailable' ],
    'draft product'   => [ fn () => [ 'product_id' => pricedProduct( 100, [ 'status' => 'draft' ] )->id, 'quantity' => 1 ], 'product-unavailable' ],
    'unpriced'        => [ fn () => [ 'product_id' => Product::factory()->create()->id, 'quantity' => 1 ], 'price-unavailable' ],
    'foreign variant' => [ fn () => [ 'product_id' => pricedProduct()->id, 'product_variant_id' => ProductVariant::factory()->create()->id, 'quantity' => 1 ], 'variant-unavailable' ],
] );

it( 'validates the add-item payload', function (): void {
    $token = newCartToken( $this );

    $this->postJson( "/api/ecommerce/v1/carts/{$token}/items", [ 'product_id' => 1, 'quantity' => 0 ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'quantity' );
} );

it( 'removes items and refuses items from another cart', function (): void {
    $product = pricedProduct();
    $token   = newCartToken( $this );
    $other   = newCartToken( $this );

    $this->postJson( "/api/ecommerce/v1/carts/{$token}/items", [ 'product_id' => $product->id, 'quantity' => 1 ], idem() )->assertCreated();
    $item = CartItem::query()->sole();

    $this->deleteJson( "/api/ecommerce/v1/carts/{$other}/items/{$item->id}", [], idem() )->assertNotFound();
    $this->deleteJson( "/api/ecommerce/v1/carts/{$token}/items/{$item->id}", [], idem() )->assertOk()->assertJsonCount( 0, 'data.items' );
} );

it( 'applies and removes a coupon', function (): void {
    percentCoupon( 'SAVE10', 10 );
    $product = pricedProduct( 5_000 );
    $token   = newCartToken( $this );

    $this->postJson( "/api/ecommerce/v1/carts/{$token}/items", [ 'product_id' => $product->id, 'quantity' => 2 ], idem() )->assertCreated();

    $this->postJson( "/api/ecommerce/v1/carts/{$token}/coupons", [ 'code' => ' save10 ' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.meta.coupon_code', 'SAVE10' )
        ->assertJsonPath( 'data.discount.amount', 1_000 )
        ->assertJsonPath( 'data.total.amount', 9_000 );

    $this->deleteJson( "/api/ecommerce/v1/carts/{$token}/coupons/save10", [], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.discount.amount', 0 )
        ->assertJsonPath( 'data.total.amount', 10_000 );

    $this->deleteJson( "/api/ecommerce/v1/carts/{$token}/coupons/save10", [], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.code', 'coupon-not-applied' );
} );

it( 'rejects an invalid coupon', function (): void {
    $token = newCartToken( $this );

    $this->postJson( "/api/ecommerce/v1/carts/{$token}/coupons", [ 'code' => 'NOPE' ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'code' )
        ->assertJsonPath( 'errors.0.code', 'coupon-invalid' );

    expect( Cart::query()->where( 'token', $token )->value( 'meta' ) )->not->toContain( 'NOPE' );
} );

it( 'returns 404 for an unknown cart', function (): void {
    $this->postJson( '/api/ecommerce/v1/carts/' . str_repeat( 'a', 40 ) . '/items', [ 'product_id' => 1, 'quantity' => 1 ], idem() )->assertNotFound();
} );
