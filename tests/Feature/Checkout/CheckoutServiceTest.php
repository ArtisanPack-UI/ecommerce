<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Checkout\CheckoutState;
use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentFinalization;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/CheckoutTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->gateway  = checkoutGateway();
    $this->carts    = app( StorefrontCartService::class );
    $this->checkout = app( CheckoutService::class );
    $this->product  = checkoutProduct();
    checkoutZone();
} );

function expectCheckoutError( Closure $call, string $code ): void
{
    expect( $call )->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( $code ) );
}

describe( 'start', function (): void {
    it( 'holds stock, moves to addressing, and fires checkout.started once', function (): void {
        InventoryItem::factory()->create( [ 'stockable_type' => $this->product->getMorphClass(), 'stockable_id' => $this->product->id, 'quantity_on_hand' => 10 ] );
        $cart    = $this->carts->create( 'USD' );
        $started = 0;
        addAction( 'ap.ecommerce.checkout.started', function () use ( &$started ): void {
            ++$started;
        } );

        $this->carts->addItem( $cart, $this->product->id, null, 3 );
        $first = $this->checkout->start( $cart );
        $this->checkout->start( $cart );

        expect( $first->hasAdjustments() )->toBeFalse()
            ->and( $cart->refresh()->checkout_state )->toBe( CheckoutState::ADDRESSING )
            ->and( $cart->checkout_started_at )->not->toBeNull()
            ->and( $started )->toBe( 1 )
            ->and( (int) InventoryReservation::query()->where( 'reservable_id', $cart->id )->sum( 'quantity' ) )->toBe( 3 )
            ->and( InventoryItem::query()->sole()->quantity_reserved )->toBe( 3 );
    } );

    it( 'reduces or removes lines another shopper\'s holds leave short, and reports them', function (): void {
        $scarce = checkoutProduct();
        $gone   = checkoutProduct();
        InventoryItem::factory()->create( [ 'stockable_type' => $scarce->getMorphClass(), 'stockable_id' => $scarce->id, 'quantity_on_hand' => 3 ] );
        InventoryItem::factory()->create( [ 'stockable_type' => $gone->getMorphClass(), 'stockable_id' => $gone->id, 'quantity_on_hand' => 1 ] );

        $mine  = $this->carts->create( 'USD' );
        $other = $this->carts->create( 'USD' );
        $this->carts->addItem( $mine, $scarce->id, null, 3 );
        $this->carts->addItem( $mine, $gone->id, null, 1 );
        $this->carts->addItem( $other, $scarce->id, null, 2 );
        $this->carts->addItem( $other, $gone->id, null, 1 );
        $this->checkout->start( $other );

        $start = $this->checkout->start( $mine );

        expect( collect( $start->adjustments )->pluck( 'available', 'product_id' )->all() )->toBe( [ $scarce->id => 1, $gone->id => 0 ] )
            ->and( $mine->items()->sole()->quantity )->toBe( 1 )
            ->and( $mine->refresh()->subtotal_amount )->toBe( 2_000 );
    } );

    it( 'refuses an empty cart', function (): void {
        expectCheckoutError( fn () => $this->checkout->start( $this->carts->create( 'USD' ) ), 'cart-empty' );
    } );
} );

describe( 'steps', function (): void {
    it( 'moves through addressing, shipping selection and payment selection', function (): void {
        $cart     = $this->carts->create( 'USD' );
        $captured = [];
        addAction( 'ap.ecommerce.checkout.addressCaptured', function ( $cart, $address, string $type ) use ( &$captured ): void {
            $captured[] = $type;
        } );

        $this->carts->addItem( $cart, $this->product->id, null, 1 );
        $this->checkout->setEmail( $cart, 'ADA@example.test' );
        $this->checkout->setAddress( $cart, checkoutAddress() );

        expect( $cart->refresh()->checkout_state )->toBe( CheckoutState::SHIPPING_SELECTION )
            ->and( $cart->email )->toBe( 'ada@example.test' )
            ->and( $captured )->toBe( [ 'shipping', 'billing' ] );

        $rate = $this->checkout->shippingRates( $cart )->first();
        $this->checkout->setShippingMethod( $cart, $rate->id() );

        expect( $cart->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_SELECTION )
            ->and( $cart->shipping_amount )->toBe( 500 );
    } );

    it( 'skips shipping selection for an order that doesn\'t ship', function (): void {
        $cart = $this->carts->create( 'USD' );
        $this->carts->addItem( $cart, checkoutProduct( [ 'USD' => 900 ], [ 'type' => 'digital' ] )->id, null, 1 );

        $this->checkout->setAddress( $cart, null, checkoutAddress() );

        expect( $cart->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_SELECTION );
    } );

    it( 'refuses an incomplete address and a rate that isn\'t offered', function (): void {
        $cart = $this->carts->create( 'USD' );
        $this->carts->addItem( $cart, $this->product->id, null, 1 );

        expectCheckoutError( fn () => $this->checkout->setAddress( $cart, new ArtisanPackUI\Ecommerce\ValueObjects\Address( address1: '', city: 'Chicago', countryCode: 'US' ) ), 'address-incomplete' );
        expectCheckoutError( fn () => $this->checkout->setShippingMethod( $cart, 'nope' ), 'shipping-address-required' );

        $this->checkout->setAddress( $cart, checkoutAddress() );

        expectCheckoutError( fn () => $this->checkout->setShippingMethod( $cart, 'nope' ), 'shipping-rate-unavailable' );
    } );

    it( 'lets a listener block a transition', function (): void {
        addFilter( 'ap.ecommerce.checkout.canTransitionTo', fn ( bool $allowed, Cart $cart, string $to ): bool => CheckoutState::PAYMENT_SELECTION !== $to );
        $cart = $this->carts->create( 'USD' );
        $this->carts->addItem( $cart, $this->product->id, null, 1 );
        $this->checkout->setAddress( $cart, checkoutAddress() );

        expectCheckoutError( fn () => $this->checkout->setShippingMethod( $cart, $this->checkout->shippingRates( $cart )->first()->id() ), 'transition-blocked' );
    } );

    it( 'filters the available gateways and refuses one that isn\'t offered', function (): void {
        $cart = $this->carts->create( 'USD' );

        expect( array_keys( $this->checkout->availableGateways( $cart ) ) )->toContain( 'fake' );

        addFilter( 'ap.ecommerce.checkout.availableGateways', fn ( $gateways ) => $gateways->reject( fn ( $gateway ) => 'fake' === $gateway->key() ) );

        expect( $this->checkout->availableGateways( $cart ) )->not->toHaveKey( 'fake' );
        expectCheckoutError( fn () => $this->checkout->setPaymentGateway( $cart, 'fake' ), 'gateway-unavailable' );
    } );

    it( 'follows the guest checkout policy', function (): void {
        $cart = $this->carts->create( 'USD' );
        $this->carts->addItem( $cart, $this->product->id, null, 1 );

        config()->set( 'artisanpack.ecommerce.checkout.guest_checkout', 'disabled' );
        expectCheckoutError( fn () => $this->checkout->start( $cart ), 'account-required' );

        config()->set( 'artisanpack.ecommerce.checkout.guest_checkout', 'required_account' );
        $ready = readyCart( $this->product, 1, $cart );
        expectCheckoutError( fn () => $this->checkout->createPaymentSession( $ready ), 'account-required' );

        $ready->forceFill( [ 'customer_id' => Customer::factory()->create()->id ] )->save();
        expect( $this->checkout->createPaymentSession( $ready ) )->toBeInstanceOf( PaymentSession::class );
    } );
} );

describe( 'payment session', function (): void {
    it( 'stores the reference, reuses it while nothing changes, and makes a new one when the total changes', function (): void {
        $cart      = readyCart( $this->product );
        $initiated = 0;
        addAction( 'ap.ecommerce.checkout.paymentInitiated', function () use ( &$initiated ): void {
            ++$initiated;
        } );

        $first = $this->checkout->createPaymentSession( $cart, [ 'return_url' => 'https://shop.test/back', PaymentOrchestrator::ACCEPT_CHALLENGE => true ] );
        $again = $this->checkout->createPaymentSession( $cart );

        expect( $again->reference )->toBe( $first->reference )
            ->and( (int) $first->amount->getAmount() )->toBe( 2_500 )
            ->and( $cart->refresh()->payment_reference )->toBe( $first->reference )
            ->and( $cart->checkout_state )->toBe( CheckoutState::PAYMENT_PENDING )
            ->and( $initiated )->toBe( 1 )
            ->and( $this->gateway->contexts[0] )->not->toHaveKey( PaymentOrchestrator::ACCEPT_CHALLENGE );

        $this->carts->updateItem( $cart, $cart->items()->sole(), 2 );

        expect( $cart->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_SELECTION );

        $third = $this->checkout->createPaymentSession( $cart );

        expect( $third->reference )->not->toBe( $first->reference )
            ->and( (int) $third->amount->getAmount() )->toBe( 4_500 );
    } );

    it( 'refuses a cart that isn\'t ready to pay', function ( Closure $break, string $code ): void {
        $cart = readyCart( $this->product );
        $break( $cart );

        expectCheckoutError( fn () => $this->checkout->createPaymentSession( $cart ), $code );
    } )->with( [
        'no email'      => [ fn ( Cart $cart ) => $cart->forceFill( [ 'email' => null ] )->save(), 'email-required' ],
        'no address'    => [ fn ( Cart $cart ) => $cart->forceFill( [ 'shipping_address' => null, 'billing_address' => null ] )->save(), 'shipping-address-required' ],
        'no rate'       => [ fn ( Cart $cart ) => $cart->forceFill( [ 'meta' => [] ] )->save(), 'shipping-rate-required' ],
        'no gateway'    => [ fn ( Cart $cart ) => $cart->forceFill( [ 'payment_gateway_key' => null ] )->save(), 'gateway-required' ],
    ] );
} );

describe( 'finalize', function (): void {
    it( 'places the order and captures the session the shopper confirmed', function (): void {
        InventoryItem::factory()->create( [ 'stockable_type' => $this->product->getMorphClass(), 'stockable_id' => $this->product->id, 'quantity_on_hand' => 5 ] );
        $cart      = readyCart( $this->product, 2 );
        $finalized = 0;
        addAction( 'ap.ecommerce.checkout.finalized', function () use ( &$finalized ): void {
            ++$finalized;
        } );

        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference );

        $result = $this->checkout->finalize( $cart, $session->reference, [ 'ip_address' => '203.0.113.9' ] );
        $order  = $result->order->refresh();

        expect( $result->isComplete() )->toBeTrue()
            ->and( $order->payment_status )->toBe( 'paid' )
            ->and( $order->system_status )->toBe( 'processing' )
            ->and( $order->payment_reference )->toBe( $session->reference )
            ->and( $order->total_amount )->toBe( 4_500 )
            ->and( $order->ip_address )->toBe( '203.0.113.9' )
            ->and( $cart->refresh()->checkout_state )->toBe( CheckoutState::COMPLETED )
            ->and( $cart->completed_order_id )->toBe( $order->id )
            ->and( InventoryItem::query()->sole()->quantity_on_hand )->toBe( 3 )
            ->and( $finalized )->toBe( 1 )
            ->and( array_count_values( $this->gateway->calls )['createPaymentSession'] )->toBe( 1 );
    } );

    it( 'returns the same order on a repeat call without charging again', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference );

        $first  = $this->checkout->finalize( $cart, $session->reference );
        $second = $this->checkout->finalize( $cart, $session->reference );

        expect( $second->order->id )->toBe( $first->order->id )
            ->and( Order::query()->count() )->toBe( 1 )
            ->and( array_count_values( $this->gateway->calls )['capturePayment'] )->toBe( 1 );
    } );

    it( 'resumes after a 3DS step-up with the same reference', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_REQUIRES_ACTION );

        $challenged = $this->checkout->finalize( $cart, $session->reference );

        expect( $challenged->status() )->toBe( PaymentFinalization::STATUS_CHALLENGED )
            ->and( $challenged->stepUpToken() )->not->toBeNull()
            ->and( $cart->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_PENDING );

        $this->gateway->confirm( $session->reference );
        $resumed = $this->checkout->finalize( $cart, $session->reference );

        expect( $resumed->isComplete() )->toBeTrue()
            ->and( $resumed->order->id )->toBe( $challenged->order->id )
            ->and( $cart->refresh()->checkout_state )->toBe( CheckoutState::COMPLETED );
    } );

    it( 'refuses without a session or with someone else\'s reference', function (): void {
        $cart = readyCart( $this->product );

        expectCheckoutError( fn () => $this->checkout->finalize( $cart ), 'payment-session-required' );

        $this->checkout->createPaymentSession( $cart );

        expectCheckoutError( fn () => $this->checkout->finalize( $cart, 'ps_other' ), 'payment-reference-mismatch' );
        expect( Order::query()->count() )->toBe( 0 );
    } );

    it( 'refuses when the total changed after the payment was set up', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference );

        ProductPrice::query()->where( 'priceable_id', $this->product->id )->update( [ 'price_amount' => 3_000 ] );

        expectCheckoutError( fn () => $this->checkout->finalize( $cart, $session->reference ), 'totals-changed' );

        expect( Order::query()->count() )->toBe( 0 )
            ->and( $cart->refresh()->payment_reference )->toBeNull()
            ->and( $cart->checkout_state )->toBe( CheckoutState::PAYMENT_SELECTION );
    } );

    it( 'places a zero-total order without a gateway', function (): void {
        $cart = readyCart( checkoutProduct( [ 'USD' => 0 ], [ 'type' => 'digital' ] ) );

        $result = $this->checkout->finalize( $cart );

        expect( $result->isComplete() )->toBeTrue()
            ->and( $result->order->refresh()->payment_status )->toBe( 'paid' )
            ->and( $result->order->system_status )->toBe( 'processing' )
            ->and( $result->order->payment_reference )->toBeNull()
            ->and( $this->gateway->calls )->toBe( [] );
    } );
} );
