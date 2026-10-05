<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/ApiTestHelpers.php';
require_once __DIR__ . '/../Checkout/CheckoutTestHelpers.php';
require_once __DIR__ . '/../GraphQL/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    checkoutZone();
    $this->cart = app( StorefrontCartService::class )->create( 'USD' );
    app( StorefrontCartService::class )->addItem( $this->cart, checkoutProduct()->id, null, 1 );
    $this->base = "/api/ecommerce/v1/carts/{$this->cart->token}";
} );

it( 'quotes rates for a destination, selects one, and refuses a forged id (F4)', function (): void {
    $rates = $this->getJson( "{$this->base}/shipping-rates?country_code=US&postal_code=60601" )
        ->assertOk()
        ->assertJsonPath( 'data.0.amount', 500 )
        ->json( 'data' );

    $this->putJson( "{$this->base}/shipping-rate", [ 'rate_id' => $rates[0]['id'], 'destination' => [ 'country_code' => 'US', 'postal_code' => '60601' ] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.shipping.amount', 500 )
        ->assertJsonPath( 'data.total.amount', 2_500 );

    $this->putJson( "{$this->base}/shipping-rate", [ 'rate_id' => 'forged', 'destination' => [ 'country_code' => 'US' ] ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.code', 'shipping-rate-unavailable' );

    $this->getJson( "{$this->base}/shipping-rates" )->assertStatus( 422 );
} );

it( 'updates the cart\'s email and address, and clears it', function (): void {
    $this->patchJson( $this->base, [ 'email' => 'ADA@example.test', 'shipping_address' => checkoutAddress()->toArray() ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.email', 'ada@example.test' )
        ->assertJsonPath( 'data.shipping_address.city', 'Chicago' );

    $this->deleteJson( "{$this->base}/items", [], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.subtotal.amount', 0 );

    expect( $this->cart->items()->count() )->toBe( 0 );
} );

it( 'does the same through GraphQL', function (): void {
    $rates = gql( $this, 'query ($t: String!) { cartShippingRates(token: $t, country_code: "US") }', [ 't' => $this->cart->token ] )->json( 'data.cartShippingRates' );

    gql( $this, 'mutation ($t: String!, $r: String!) { selectCartShippingRate(input: { cart_token: $t, rate_id: $r, destination: { country_code: "US" } }) { cart { shipping { amount } } errors { code } } }', [ 't' => $this->cart->token, 'r' => $rates[0]['id'] ] )
        ->assertJsonPath( 'data.selectCartShippingRate.cart.shipping.amount', 500 );

    gql( $this, 'mutation ($t: String!) { updateCart(input: { cart_token: $t, email: "ada@example.test" }) { cart { email } } }', [ 't' => $this->cart->token ] )
        ->assertJsonPath( 'data.updateCart.cart.email', 'ada@example.test' );

    gql( $this, 'mutation ($t: String!) { clearCart(input: { cart_token: $t }) { cart { subtotal { amount } } } }', [ 't' => $this->cart->token ] )
        ->assertJsonPath( 'data.clearCart.cart.subtotal.amount', 0 );
} );
