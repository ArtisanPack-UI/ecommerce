<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\CartAbandoned;
use ArtisanPackUI\Ecommerce\Exceptions\ProductWriteException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use ArtisanPackUI\Ecommerce\Services\ProductService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

uses( RefreshDatabase::class );

/**
 * A cart in checkout, last touched `$minutesAgo` minutes ago.
 *
 * @param  array<string, mixed>  $attributes
 */
function lifecycleCart( int $minutesAgo, array $attributes = [] ): Cart
{
    $cart = Cart::factory()->create( $attributes + [ 'email' => 'ada@example.test', 'checkout_started_at' => Carbon::now()->subHours( 3 ), 'expires_at' => Carbon::now()->addDays( 10 ) ] );
    Cart::query()->whereKey( $cart->id )->toBase()->update( [ 'updated_at' => Carbon::now()->subMinutes( $minutesAgo ) ] );

    return $cart->refresh();
}

describe( 'ecommerce:flag-abandoned-carts', function (): void {
    it( 'flags carts left in checkout once, firing the hook and event once each', function (): void {
        Event::fake( [ CartAbandoned::class ] );
        $hooks = 0;
        addAction( 'ap.ecommerce.cart.abandoned', function () use ( &$hooks ): void {
            ++$hooks;
        } );

        $left = lifecycleCart( 120 );

        $this->artisan( 'ecommerce:flag-abandoned-carts' )->expectsOutputToContain( 'Flagged 1' )->assertSuccessful();
        $this->artisan( 'ecommerce:flag-abandoned-carts' )->expectsOutputToContain( 'Flagged 0' )->assertSuccessful();

        expect( $left->refresh()->abandoned_at )->not->toBeNull()
            ->and( $hooks )->toBe( 1 );

        Event::assertDispatchedTimes( CartAbandoned::class, 1 );
    } );

    it( 'leaves carts that don\'t qualify', function ( array $attributes, int $minutesAgo ): void {
        $cart = lifecycleCart( $minutesAgo, $attributes );

        $this->artisan( 'ecommerce:flag-abandoned-carts' )->assertSuccessful();

        expect( $cart->refresh()->abandoned_at )->toBeNull();
    } )->with( [
        'recently touched'  => [ [], 30 ],
        'no email'          => [ [ 'email' => null ], 120 ],
        'checkout not used' => [ [ 'checkout_started_at' => null ], 120 ],
        'became an order'   => [ [ 'completed_order_id' => 7 ], 120 ],
        'expired'           => [ [ 'expires_at' => Carbon::now()->subMinute() ], 120 ],
    ] );

    it( 'follows cart.abandoned_after_minutes', function (): void {
        config()->set( 'artisanpack.ecommerce.cart.abandoned_after_minutes', 15 );
        $cart = lifecycleCart( 20 );

        $this->artisan( 'ecommerce:flag-abandoned-carts' )->assertSuccessful();

        expect( $cart->refresh()->abandoned_at )->not->toBeNull();
    } );

    it( 'clears the flag when the shopper comes back and changes the cart', function (): void {
        $product = Product::factory()->create();
        ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => 1_000 ] );
        $cart = lifecycleCart( 120 );

        $this->artisan( 'ecommerce:flag-abandoned-carts' )->assertSuccessful();
        app( StorefrontCartService::class )->addItem( $cart, $product->id, null, 1 );

        expect( $cart->refresh()->abandoned_at )->toBeNull();
    } );
} );

describe( 'ecommerce:prune-carts', function (): void {
    it( 'deletes expired carts and releases their holds, so their products can be deleted (D7)', function (): void {
        $product = Product::factory()->create();
        $item    = InventoryItem::factory()->create( [ 'stockable_type' => $product->getMorphClass(), 'stockable_id' => $product->id, 'quantity_on_hand' => 5 ] );
        $expired = Cart::factory()->create( [ 'expires_at' => Carbon::now()->subDay() ] );
        CartItem::factory()->create( [ 'cart_id' => $expired->id, 'product_id' => $product->id ] );
        app( InventoryService::class )->reserve( $item, $expired, 2 );

        $this->artisan( 'ecommerce:prune-carts' )->expectsOutputToContain( 'Pruned 1' )->assertSuccessful();

        expect( Cart::query()->whereKey( $expired->id )->exists() )->toBeFalse()
            ->and( InventoryReservation::query()->count() )->toBe( 0 )
            ->and( $item->refresh()->quantity_reserved )->toBe( 0 );

        app( ProductService::class )->delete( $product );

        expect( Product::query()->whereKey( $product->id )->exists() )->toBeFalse();
    } );

    it( 'keeps live carts, and recently converted ones', function (): void {
        $live      = Cart::factory()->create( [ 'expires_at' => Carbon::now()->addDay() ] );
        $converted = Cart::factory()->create( [ 'completed_order_id' => 3 ] );
        $old       = Cart::factory()->create( [ 'completed_order_id' => 4 ] );
        Cart::query()->whereKey( $old->id )->toBase()->update( [ 'updated_at' => Carbon::now()->subDays( 40 ) ] );

        $this->artisan( 'ecommerce:prune-carts' )->assertSuccessful();

        expect( Cart::query()->pluck( 'id' )->all() )->toEqualCanonicalizing( [ $live->id, $converted->id ] );
    } );
} );

it( 'refuses to delete a product in an open cart but not one left only in expired carts', function (): void {
    $product = Product::factory()->create();
    $open    = Cart::factory()->create( [ 'expires_at' => Carbon::now()->addDay() ] );
    $stale   = Cart::factory()->create( [ 'expires_at' => Carbon::now()->subDay() ] );
    CartItem::factory()->create( [ 'cart_id' => $open->id, 'product_id' => $product->id ] );
    CartItem::factory()->create( [ 'cart_id' => $stale->id, 'product_id' => $product->id ] );

    expect( fn () => app( ProductService::class )->delete( $product ) )->toThrow( ProductWriteException::class );

    CartItem::query()->where( 'cart_id', $open->id )->delete();
    app( ProductService::class )->delete( $product );

    expect( CartItem::query()->count() )->toBe( 0 );
} );
