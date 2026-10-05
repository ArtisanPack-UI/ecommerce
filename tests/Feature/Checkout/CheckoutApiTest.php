<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\ApiUser;

require_once __DIR__ . '/CheckoutTestHelpers.php';
require_once __DIR__ . '/../Api/ApiTestHelpers.php';
require_once __DIR__ . '/../GraphQL/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->gateway = checkoutGateway();
    checkoutZone();

    $this->product = checkoutProduct();
    $this->cart    = app( StorefrontCartService::class )->create( 'USD' );
    app( StorefrontCartService::class )->addItem( $this->cart, $this->product->id, null, 1 );
} );

function checkoutUrl( string $token, string $step = '' ): string
{
    return "/api/ecommerce/v1/checkout/{$token}" . ( '' === $step ? '' : "/{$step}" );
}

it( 'runs a checkout end to end over REST', function (): void {
    $token = $this->cart->token;

    $this->postJson( checkoutUrl( $token, 'start' ), [], idem() )
        ->assertOk()
        ->assertJsonPath( 'checkout.state', 'addressing' )
        ->assertJsonPath( 'checkout.adjustments', [] );

    $rates = $this->postJson( checkoutUrl( $token, 'address' ), [
        'email'            => 'ada@example.test',
        'shipping_address' => checkoutAddress()->toArray(),
    ], idem() )
        ->assertOk()
        ->assertJsonPath( 'checkout.state', 'shipping_selection' )
        ->assertJsonPath( 'checkout.requires_shipping', true )
        ->assertJsonPath( 'checkout.gateways.0.key', 'fake' )
        ->json( 'checkout.shipping_rates' );

    $this->postJson( checkoutUrl( $token, 'shipping-method' ), [ 'rate_id' => $rates[0]['id'] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.shipping.amount', 500 )
        ->assertJsonPath( 'checkout.state', 'payment_selection' );

    $this->postJson( checkoutUrl( $token, 'payment-gateway' ), [ 'gateway' => 'fake' ], idem() )->assertOk();

    $reference = $this->postJson( checkoutUrl( $token, 'session' ), [ 'return_url' => 'http://localhost/checkout/done' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'checkout.state', 'payment_pending' )
        ->assertJsonPath( 'checkout.payment.amount.amount', 2_500 )
        ->json( 'checkout.payment.reference' );

    $this->gateway->confirm( $reference );

    $this->postJson( checkoutUrl( $token, 'finalize' ), [ 'payment_reference' => $reference, 'customer_note' => 'Thanks!' ], idem() )
        ->assertCreated()
        ->assertJsonPath( 'checkout.status', 'captured' )
        ->assertJsonPath( 'data.total.amount', 2_500 );

    expect( Order::query()->sole()->customer_note )->toBe( 'Thanks!' );
} );

it( 'answers a shopper-fixable failure as a problem with its code', function (): void {
    $this->postJson( checkoutUrl( $this->cart->token, 'shipping-method' ), [ 'rate_id' => 'x' ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.code', 'shipping-address-required' );

    $this->postJson( checkoutUrl( $this->cart->token, 'finalize' ), [], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'title', 'Checkout failed' );
} );

it( 'refuses a return URL on another site', function (): void {
    $cart = readyCart( $this->product, 1, $this->cart );

    $this->postJson( checkoutUrl( $cart->token, 'session' ), [ 'return_url' => 'https://evil.example/steal' ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.code', 'return-url-invalid' );
} );

it( 'needs the account\'s session for an account\'s cart', function (): void {
    $this->cart->forceFill( [ 'customer_id' => Customer::factory()->forUser( 9 )->create()->id ] )->save();

    $this->getJson( checkoutUrl( $this->cart->token ) )->assertNotFound();

    Sanctum::actingAs( ApiUser::make( 9 ), [ TokenAbilities::STOREFRONT ] );

    $this->getJson( checkoutUrl( $this->cart->token ) )->assertOk();
} );

it( 'runs a checkout through the GraphQL mutations', function (): void {
    $token = $this->cart->token;

    gql( $this, 'query ($t: String!) { checkout(token: $t) { state requires_shipping gateways guest_checkout } }', [ 't' => $token ] )
        ->assertJsonPath( 'data.checkout.state', 'not_started' )
        ->assertJsonPath( 'data.checkout.requires_shipping', true )
        ->assertJsonPath( 'data.checkout.gateways.0.key', 'fake' )
        ->assertJsonPath( 'data.checkout.guest_checkout', 'allowed' );

    $rates = gql( $this, 'mutation ($t: String!, $a: JSON) { setCheckoutAddress(input: { cart_token: $t, email: "ada@example.test", shipping_address: $a }) { shipping_rates errors { code } } }', [ 't' => $token, 'a' => checkoutAddress()->toArray() ] )
        ->assertJsonPath( 'data.setCheckoutAddress.errors', [] )
        ->json( 'data.setCheckoutAddress.shipping_rates' );

    gql( $this, 'mutation ($t: String!, $r: String!) { setShippingMethod(input: { cart_token: $t, rate_id: $r }) { cart { checkout_state } errors { code } } }', [ 't' => $token, 'r' => $rates[0]['id'] ] )
        ->assertJsonPath( 'data.setShippingMethod.cart.checkout_state', 'payment_selection' );

    gql( $this, 'mutation ($t: String!) { setPaymentGateway(input: { cart_token: $t, gateway: "fake" }) { errors { code } } }', [ 't' => $token ] )
        ->assertJsonPath( 'data.setPaymentGateway.errors', [] );

    $reference = gql( $this, 'mutation ($t: String!) { createPaymentSession(input: { cart_token: $t }) { session { reference amount { amount } } errors { code } } }', [ 't' => $token ] )
        ->assertJsonPath( 'data.createPaymentSession.session.amount.amount', 2_500 )
        ->json( 'data.createPaymentSession.session.reference' );

    $this->gateway->confirm( $reference );

    gql( $this, 'mutation ($t: String!, $r: String) { placeOrder(input: { cart_token: $t, payment_reference: $r }) { status complete order { order_number } errors { code } } }', [ 't' => $token, 'r' => $reference ] )
        ->assertJsonPath( 'data.placeOrder.status', 'captured' )
        ->assertJsonPath( 'data.placeOrder.complete', true )
        ->assertJsonPath( 'data.placeOrder.order.order_number', Order::query()->sole()->order_number );
} );
