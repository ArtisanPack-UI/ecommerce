<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\Shipping\ZoneShippingRateProvider;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Money\Money;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->storefront = app( StorefrontCartService::class );
    $this->cart       = $this->storefront->create( 'USD' );
    $this->address    = new Address( address1: '1 Main', city: 'Chicago', countryCode: 'US', regionCode: 'IL', postalCode: '60601' );
} );

/**
 * An active product priced at `$amount` USD.
 */
function sfHooksProduct( int $amount = 2_500 ): Product
{
    $product = Product::factory()->create();
    ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => $amount ] );

    return $product;
}

/**
 * A US zone with one flat-rate method.
 */
function sfHooksFlatRate( int $amount = 500 ): ShippingMethod
{
    $zone = ShippingZone::factory()->create( [ 'name' => 'US', 'country_codes' => [ 'US' ] ] );

    return ShippingMethod::factory()->create( [ 'zone_id' => $zone->id, 'key' => 'flat-rate', 'label' => 'Standard', 'config' => [ 'amount' => $amount ] ] );
}

it( 'runs each line price through pricing.itemPrice with the line and cart', function (): void {
    $product  = sfHooksProduct( 2_500 );
    $received = [];

    addFilter( 'ap.ecommerce.pricing.itemPrice', function ( Money $price, CartItem $item, Cart $cart ) use ( &$received ): Money {
        $received[] = [ (int) $price->getAmount(), $item->product_id, $cart->id ];

        return $price->subtract( Money::USD( 500 ) );
    } );

    $item = $this->storefront->addItem( $this->cart, $product->id, null, 2 );

    expect( $received[0] )->toBe( [ 2_500, $product->id, $this->cart->id ] );
    expect( $item->unit_price_amount )->toBe( 2_000 );
    expect( $this->cart->subtotal_amount )->toBe( 4_000 );
    expect( $this->cart->total_amount )->toBe( 4_000 );
} );

it( 'ignores a pricing.itemPrice return that is not a non-negative Money in the cart currency', function ( mixed $return ): void {
    $product = sfHooksProduct( 2_500 );

    addFilter( 'ap.ecommerce.pricing.itemPrice', fn (): mixed => $return );

    $item = $this->storefront->addItem( $this->cart, $product->id, null, 1 );

    expect( $item->unit_price_amount )->toBe( 2_500 );
} )->with( [
    'a non-Money'    => [ 1_000 ],
    'other currency' => [ Money::EUR( 1_000 ) ],
    'negative'       => [ Money::USD( -1 ) ],
] );

it( 'runs the subtotal through pricing.subtotal and the total through pricing.total with a breakdown', function (): void {
    $product  = sfHooksProduct( 2_500 );
    $received = [];

    addFilter( 'ap.ecommerce.pricing.subtotal', function ( Money $subtotal, Cart $cart ) use ( &$received ): Money {
        $received['subtotal'] = [ (int) $subtotal->getAmount(), $cart->id ];

        return $subtotal->add( Money::USD( 100 ) );
    } );
    addFilter( 'ap.ecommerce.pricing.total', function ( Money $total, Cart $cart, array $breakdown ) use ( &$received ): Money {
        $received['total']     = [ (int) $total->getAmount(), $cart->id ];
        $received['breakdown'] = array_map( static fn ( Money $money ): int => (int) $money->getAmount(), $breakdown );

        return $total->add( Money::USD( 50 ) );
    } );

    $this->storefront->addItem( $this->cart, $product->id, null, 1 );

    expect( $received['subtotal'] )->toBe( [ 2_500, $this->cart->id ] );
    expect( $received['total'] )->toBe( [ 2_600, $this->cart->id ] );
    expect( $received['breakdown'] )->toBe( [ 'subtotal' => 2_600, 'discount' => 0, 'tax' => 0, 'shipping' => 0 ] );
    expect( $this->cart->subtotal_amount )->toBe( 2_600 );
    expect( $this->cart->total_amount )->toBe( 2_650 );
} );

it( 'ignores invalid pricing.subtotal and pricing.total returns', function (): void {
    $product = sfHooksProduct( 2_500 );

    addFilter( 'ap.ecommerce.pricing.subtotal', fn (): Money => Money::USD( -10 ) );
    addFilter( 'ap.ecommerce.pricing.total', fn (): string => 'free' );

    $this->storefront->addItem( $this->cart, $product->id, null, 1 );

    expect( $this->cart->subtotal_amount )->toBe( 2_500 );
    expect( $this->cart->total_amount )->toBe( 2_500 );
} );

it( 'clears a storefront cart, drops the shipping selection, and fires cart.cleared', function (): void {
    sfHooksFlatRate( 500 );
    $this->storefront->addItem( $this->cart, sfHooksProduct( 2_500 )->id, null, 1 );
    $this->storefront->selectShippingMethod( $this->cart, $this->address, sfHooksRateId( $this ) );
    $reason = null;

    addAction( 'ap.ecommerce.cart.cleared', function ( Cart $cart, string $why ) use ( &$reason ): void {
        $reason = $why;
    } );

    $cart = $this->storefront->clear( $this->cart, 'expired' );

    expect( $reason )->toBe( 'expired' );
    expect( $cart->items )->toHaveCount( 0 );
    expect( $cart->shipping_amount )->toBe( 0 );
    expect( $cart->total_amount )->toBe( 0 );
    expect( $cart->meta )->not->toHaveKey( StorefrontCartService::SHIPPING_RATE_META_KEY );
} );

it( 'selects a re-quoted shipping rate, adds it to the total, and fires shipping.methodSelected', function (): void {
    $method = sfHooksFlatRate( 500 );
    $this->storefront->addItem( $this->cart, sfHooksProduct( 2_500 )->id, null, 1 );
    $fired = null;

    addAction( 'ap.ecommerce.shipping.methodSelected', function ( ShippingRate $rate, Cart $cart, ?ShippingMethod $selected ) use ( &$fired ): void {
        $fired = [ $rate->id(), (int) $rate->amount->getAmount(), $cart->id, $selected?->id ];
    } );

    $rateId = sfHooksRateId( $this );
    $cart   = $this->storefront->selectShippingMethod( $this->cart, $this->address, $rateId );

    expect( $fired )->toBe( [ $rateId, 500, $this->cart->id, $method->id ] );
    expect( $cart->shipping_amount )->toBe( 500 );
    expect( $cart->total_amount )->toBe( 3_000 );
    expect( $cart->meta[ StorefrontCartService::SHIPPING_RATE_META_KEY ]['id'] )->toBe( $rateId );
    expect( $cart->meta[ StorefrontCartService::SHIPPING_RATE_META_KEY ]['destination'] )
        ->toBe( [ 'country_code' => 'US', 'region_code' => 'IL', 'postal_code' => '60601' ] );
    expect( Cart::query()->find( $this->cart->id )->shipping_amount )->toBe( 500 );
} );

it( 'refuses a shipping rate id that is not quoted for the cart, without firing methodSelected', function (): void {
    sfHooksFlatRate( 500 );
    $this->storefront->addItem( $this->cart, sfHooksProduct( 2_500 )->id, null, 1 );
    $fired = false;

    addAction( 'ap.ecommerce.shipping.methodSelected', function () use ( &$fired ): void {
        $fired = true;
    } );

    expect( fn () => $this->storefront->selectShippingMethod( $this->cart, $this->address, '999:flat-rate' ) )
        ->toThrow( CartOperationException::class );
    expect( $fired )->toBeFalse();
    expect( Cart::query()->find( $this->cart->id )->shipping_amount )->toBe( 0 );
} );

/**
 * The id of the first rate quoted for the test's cart and address.
 */
function sfHooksRateId( $test ): string
{
    return app( ZoneShippingRateProvider::class )
        ->getRatesForCart( $test->cart->fresh(), $test->address )
        ->first()
        ->id();
}

it( 'drops the shipping selection when the last line is removed', function (): void {
    sfHooksFlatRate( 500 );
    $item = $this->storefront->addItem( $this->cart, sfHooksProduct( 2_500 )->id, null, 1 );
    $this->storefront->selectShippingMethod( $this->cart, $this->address, sfHooksRateId( $this ) );

    $this->storefront->removeItem( $this->cart, $item );

    expect( $this->cart->shipping_amount )->toBe( 0 );
    expect( $this->cart->total_amount )->toBe( 0 );
    expect( $this->cart->meta )->not->toHaveKey( StorefrontCartService::SHIPPING_RATE_META_KEY );
} );

it( 'keeps the shipping selection while the cart still has lines', function (): void {
    sfHooksFlatRate( 500 );
    $this->storefront->addItem( $this->cart, sfHooksProduct( 2_500 )->id, null, 1 );
    $second = $this->storefront->addItem( $this->cart, sfHooksProduct( 1_000 )->id, null, 1 );
    $this->storefront->selectShippingMethod( $this->cart, $this->address, sfHooksRateId( $this ) );

    $this->storefront->removeItem( $this->cart, $second );

    expect( $this->cart->shipping_amount )->toBe( 500 );
    expect( $this->cart->total_amount )->toBe( 3_000 );
} );
