<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use Tests\Fixtures\CheckoutFakeGateway;

if ( ! function_exists( 'checkoutProduct' ) ) {
    /**
     * A sellable product priced in each given currency.
     *
     * @param  array<string, int>    $prices
     * @param  array<string, mixed>  $attributes
     */
    function checkoutProduct( array $prices = [ 'USD' => 2_000 ], array $attributes = [] ): Product
    {
        $product = Product::factory()->create( $attributes );

        foreach ( $prices as $currency => $amount ) {
            ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => $currency, 'price_amount' => $amount ] );
        }

        return $product;
    }
}

if ( ! function_exists( 'checkoutGateway' ) ) {
    /**
     * Registers (and returns) the two-phase fake gateway.
     */
    function checkoutGateway(): CheckoutFakeGateway
    {
        $gateway = new CheckoutFakeGateway();
        app( PaymentGatewayRegistry::class )->register( $gateway->key(), $gateway );

        return $gateway;
    }
}

if ( ! function_exists( 'checkoutZone' ) ) {
    /**
     * A US zone with a 5.00 flat rate.
     */
    function checkoutZone( int $amount = 500 ): ShippingZone
    {
        $zone = ShippingZone::factory()->create( [ 'name' => 'US', 'country_codes' => [ 'US' ] ] );
        ShippingMethod::factory()->create( [ 'zone_id' => $zone->id, 'key' => 'flat-rate', 'label' => 'Standard', 'config' => [ 'amount' => $amount ], 'position' => 0 ] );

        return $zone;
    }
}

if ( ! function_exists( 'checkoutAddress' ) ) {
    function checkoutAddress(): Address
    {
        return new Address( address1: '1 Main St', city: 'Chicago', countryCode: 'US', firstName: 'Ada', lastName: 'Lovelace', regionCode: 'IL', postalCode: '60601' );
    }
}

if ( ! function_exists( 'readyCart' ) ) {
    /**
     * A cart with `$quantity` × `$product`, an email, an address, a shipping
     * rate (when it ships) and the fake gateway chosen — ready to pay.
     */
    function readyCart( Product $product, int $quantity = 1, ?Cart $cart = null ): Cart
    {
        $carts    = app( StorefrontCartService::class );
        $checkout = app( CheckoutService::class );
        $cart ??= $carts->create( 'USD' );

        $carts->addItem( $cart, $product->id, null, $quantity );
        $checkout->setEmail( $cart, 'ada@example.test' );
        $checkout->setAddress( $cart, checkoutAddress() );

        if ( $carts->requiresShipping( $cart ) ) {
            $checkout->setShippingMethod( $cart, $checkout->shippingRates( $cart )->first()->id() );
        }

        $checkout->setPaymentGateway( $cart, 'fake' );

        return $cart->refresh();
    }
}
