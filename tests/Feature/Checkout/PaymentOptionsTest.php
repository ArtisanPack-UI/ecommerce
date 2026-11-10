<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\PaymentOptionsFakeGateway;

require_once __DIR__ . '/CheckoutTestHelpers.php';
require_once __DIR__ . '/../Api/ApiTestHelpers.php';
require_once __DIR__ . '/../GraphQL/GraphQLTestHelpers.php';

/*
| Gateway payment options (#211): what an API storefront sends as
| `payment_options` reaches a gateway implementing AcceptsPaymentOptions
| only after validation, and decides whether the cart's session is reused.
*/

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->plain   = checkoutGateway();
    $this->gateway = new PaymentOptionsFakeGateway();
    app( PaymentGatewayRegistry::class )->register( $this->gateway->key(), $this->gateway );

    checkoutZone();

    $this->product  = checkoutProduct();
    $this->checkout = app( CheckoutService::class );
} );

/**
 * A cart ready to pay with `$gateway`.
 *
 * @param  string     $gateway  Gateway key.
 * @param  Cart|null  $cart     Cart to fill.
 *
 * @return Cart
 */
function optionsCart( string $gateway = 'fake-options', ?Cart $cart = null ): Cart
{
    $cart = readyCart( test()->product, 1, $cart );

    app( CheckoutService::class )->setPaymentGateway( $cart, $gateway );

    return $cart->refresh();
}

/**
 * Asserts `$call` fails with `payment-options-invalid`.
 *
 * @param  Closure  $call  Call.
 *
 * @return void
 */
function expectInvalidOptions( Closure $call ): void
{
    expect( $call )->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( 'payment-options-invalid' ) );
}

describe( 'checkout service', function (): void {
    it( 'passes the validated options to the gateway', function (): void {
        $this->checkout->createPaymentSession( optionsCart(), [ CheckoutService::PAYMENT_OPTIONS => [ 'save' => true, 'saved_method' => '12' ] ] );

        expect( $this->gateway->inner->contexts[0][ CheckoutService::PAYMENT_OPTIONS ] )->toBe( [ 'save' => true, 'saved_method' => '12' ] );
    } );

    it( 'reuses the session while the options are the same, and makes a new one when they change', function (): void {
        $cart      = optionsCart();
        $initiated = 0;
        addAction( 'ap.ecommerce.checkout.paymentInitiated', function () use ( &$initiated ): void {
            ++$initiated;
        } );

        $first = $this->checkout->createPaymentSession( $cart, [ CheckoutService::PAYMENT_OPTIONS => [ 'save' => true ] ] );
        $same  = $this->checkout->createPaymentSession( $cart->refresh(), [ CheckoutService::PAYMENT_OPTIONS => [ 'save' => true ] ] );
        $other = $this->checkout->createPaymentSession( $cart->refresh(), [ CheckoutService::PAYMENT_OPTIONS => [ 'save' => false ] ] );

        expect( $same->reference )->toBe( $first->reference )
            ->and( $other->reference )->not->toBe( $first->reference )
            ->and( $cart->refresh()->payment_reference )->toBe( $other->reference )
            ->and( $initiated )->toBe( 2 );
    } );

    it( 'keeps the current session, and sends no options, when none are given', function (): void {
        $cart  = optionsCart();
        $first = $this->checkout->createPaymentSession( $cart, [ CheckoutService::PAYMENT_OPTIONS => [ 'save' => true ] ] );
        $again = $this->checkout->createPaymentSession( $cart->refresh() );

        expect( $again->reference )->toBe( $first->reference );

        $fresh = optionsCart();
        $this->checkout->createPaymentSession( $fresh );

        expect( end( $this->gateway->inner->contexts ) )->not->toHaveKey( CheckoutService::PAYMENT_OPTIONS );
    } );

    it( 'treats an empty options object as "no options"', function (): void {
        $cart  = optionsCart();
        $first = $this->checkout->createPaymentSession( $cart, [ CheckoutService::PAYMENT_OPTIONS => [ 'save' => true ] ] );
        $reset = $this->checkout->createPaymentSession( $cart->refresh(), [ CheckoutService::PAYMENT_OPTIONS => [] ] );

        expect( $reset->reference )->not->toBe( $first->reference )
            ->and( end( $this->gateway->inner->contexts )[ CheckoutService::PAYMENT_OPTIONS ] )->toBe( [] );
    } );

    it( 'refuses options it can\'t accept, without creating a session', function ( Closure $setUp, mixed $options ): void {
        $cart = $setUp( $this );

        expectInvalidOptions( fn () => $this->checkout->createPaymentSession( $cart, [ CheckoutService::PAYMENT_OPTIONS => $options ] ) );

        expect( $this->gateway->inner->calls )->not->toContain( 'createPaymentSession' )
            ->and( $this->plain->calls )->not->toContain( 'createPaymentSession' )
            ->and( $cart->refresh()->payment_reference )->toBeNull();
    } )->with( [
        'unknown option'             => [ fn () => optionsCart(), [ 'colour' => 'red' ] ],
        'invalid value'              => [ fn () => optionsCart(), [ 'saved_method' => 0 ] ],
        'a list, not an object'      => [ fn () => optionsCart(), [ true ] ],
        'not an array'               => [ fn () => optionsCart(), 'save' ],
        'gateway without options'    => [ fn () => optionsCart( 'fake' ), [ 'save' => true ] ],
        'no option applies to it'    => [ function ( $test ): Cart {
            $test->gateway->acceptsOptions = false;

            return optionsCart();
        }, [ 'save' => true ] ],
    ] );

    it( 'still creates a session for a gateway without options when none are sent', function (): void {
        $session = $this->checkout->createPaymentSession( optionsCart( 'fake' ), [ CheckoutService::PAYMENT_OPTIONS => [] ] );

        expect( $session->gatewayKey )->toBe( 'fake' )
            ->and( $this->plain->contexts[0][ CheckoutService::PAYMENT_OPTIONS ] )->toBe( [] );
    } );
} );

describe( 'REST', function (): void {
    beforeEach( function (): void {
        $this->cart = optionsCart( 'fake-options', app( StorefrontCartService::class )->create( 'USD' ) );
    } );

    it( 'passes payment_options to the gateway', function (): void {
        $this->postJson( "/api/ecommerce/v1/checkout/{$this->cart->token}/session", [ 'payment_options' => [ 'save' => true ] ], idem() )
            ->assertOk()
            ->assertJsonPath( 'checkout.state', 'payment_pending' );

        expect( $this->gateway->inner->contexts[0][ CheckoutService::PAYMENT_OPTIONS ] )->toBe( [ 'save' => true ] );
    } );

    it( 'answers invalid payment options with their code', function (): void {
        $this->postJson( "/api/ecommerce/v1/checkout/{$this->cart->token}/session", [ 'payment_options' => [ 'colour' => 'red' ] ], idem() )
            ->assertStatus( 422 )
            ->assertJsonPath( 'errors.0.code', 'payment-options-invalid' );
    } );

    it( 'refuses payment_options that aren\'t an object', function (): void {
        $this->postJson( "/api/ecommerce/v1/checkout/{$this->cart->token}/session", [ 'payment_options' => 'save' ], idem() )
            ->assertStatus( 422 );

        expect( $this->gateway->inner->calls )->not->toContain( 'createPaymentSession' );
    } );

    it( 'sends no options when the field is left out', function (): void {
        $this->postJson( "/api/ecommerce/v1/checkout/{$this->cart->token}/session", [], idem() )->assertOk();

        expect( $this->gateway->inner->contexts[0] )->not->toHaveKey( CheckoutService::PAYMENT_OPTIONS );
    } );

    it( 'treats a null payment_options like a left-out field', function (): void {
        $url   = "/api/ecommerce/v1/checkout/{$this->cart->token}/session";
        $first = $this->postJson( $url, [ 'payment_options' => [ 'save' => true ] ], idem() )->assertOk()->json( 'checkout.payment.reference' );

        $this->postJson( $url, [ 'payment_options' => null ], idem() )
            ->assertOk()
            ->assertJsonPath( 'checkout.payment.reference', $first );

        expect( $this->gateway->inner->contexts )->toHaveCount( 1 );
    } );
} );

describe( 'GraphQL', function (): void {
    beforeEach( function (): void {
        $this->cart = optionsCart( 'fake-options', app( StorefrontCartService::class )->create( 'USD' ) );
    } );

    it( 'passes payment_options to the gateway', function (): void {
        gql( $this, 'mutation ($t: String!, $o: JSON) { createPaymentSession(input: { cart_token: $t, payment_options: $o }) { session { reference } errors { code } } }', [ 't' => $this->cart->token, 'o' => [ 'save' => true ] ] )
            ->assertJsonPath( 'data.createPaymentSession.errors', [] );

        expect( $this->gateway->inner->contexts[0][ CheckoutService::PAYMENT_OPTIONS ] )->toBe( [ 'save' => true ] );
    } );

    it( 'answers invalid payment options with their code', function (): void {
        gql( $this, 'mutation ($t: String!, $o: JSON) { createPaymentSession(input: { cart_token: $t, payment_options: $o }) { session { reference } errors { code } } }', [ 't' => $this->cart->token, 'o' => [ 'colour' => 'red' ] ] )
            ->assertJsonPath( 'data.createPaymentSession.errors.0.code', 'payment-options-invalid' );
    } );

    it( 'treats a null payment_options variable like a left-out field', function (): void {
        $mutation = 'mutation ($t: String!, $o: JSON) { createPaymentSession(input: { cart_token: $t, payment_options: $o }) { session { reference } errors { code } } }';
        $first    = gql( $this, $mutation, [ 't' => $this->cart->token, 'o' => [ 'save' => true ] ] )->json( 'data.createPaymentSession.session.reference' );

        gql( $this, $mutation, [ 't' => $this->cart->token, 'o' => null ] )
            ->assertJsonPath( 'data.createPaymentSession.errors', [] )
            ->assertJsonPath( 'data.createPaymentSession.session.reference', $first );

        expect( $this->gateway->inner->contexts )->toHaveCount( 1 );
    } );
} );
