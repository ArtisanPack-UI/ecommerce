<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Checkout\CheckoutState;
use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartPaymentSession;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\PaymentReconciler;
use ArtisanPackUI\Ecommerce\Services\StrandedPaymentService;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fixtures\CancellableCheckoutFakeGateway;
use Tests\Fixtures\CheckoutFakeGateway;

require_once __DIR__ . '/CheckoutTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->gateway = new CancellableCheckoutFakeGateway();
    app( PaymentGatewayRegistry::class )->register( 'fake', $this->gateway );

    $this->plain = new CheckoutFakeGateway( 'plain' );
    app( PaymentGatewayRegistry::class )->register( 'plain', $this->plain );

    checkoutZone();

    $this->checkout   = app( CheckoutService::class );
    $this->reconciler = app( PaymentReconciler::class );
    $this->product    = checkoutProduct();
    $this->stranded   = [];

    addAction( 'ap.ecommerce.checkout.paymentStranded', function ( Cart $cart, PaymentSession $session, string $reason ): void {
        $this->stranded[] = [ 'cart' => $cart->id, 'reference' => $session->reference, 'reason' => $reason ];
    } );
} );

/**
 * Raises the product's price so the cart's total changes (2,500 → 3,500).
 */
function strandedRaisePrice( $product ): void
{
    ProductPrice::query()->where( 'priceable_id', $product->id )->update( [ 'price_amount' => 3_000 ] );
}

function strandedExpectCode( Closure $call, string $code ): void
{
    expect( $call )->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( $code ) );
}

function strandedRow( string $reference, ?string $gatewayKey = null ): CartPaymentSession
{
    return CartPaymentSession::query()->where( 'reference', $reference )->when( null !== $gatewayKey, fn ( $query ) => $query->where( 'gateway_key', $gatewayKey ) )->sole();
}

describe( 'session history', function (): void {
    it( 'records every session, reuses the row, and marks a paid one completed', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->checkout->createPaymentSession( $cart );

        expect( CartPaymentSession::query()->count() )->toBe( 1 )
            ->and( strandedRow( $session->reference ) )
            ->cart_id->toBe( $cart->id )
            ->gateway_key->toBe( 'fake' )
            ->amount->toBe( 2_500 )
            ->currency->toBe( 'USD' )
            ->status->toBe( CartPaymentSession::STATUS_ACTIVE );

        $this->gateway->confirm( $session->reference );
        $order = $this->checkout->finalize( $cart, $session->reference )->order;

        expect( strandedRow( $session->reference ) )
            ->status->toBe( CartPaymentSession::STATUS_COMPLETED )
            ->order_id->toBe( $order->id )
            ->and( $cart->paymentSessions()->count() )->toBe( 1 )
            ->and( $this->stranded )->toBe( [] );
    } );

    it( 'keeps the old session when the shopper switches gateways', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );

        $this->checkout->setPaymentGateway( $cart, 'plain' );

        expect( strandedRow( $session->reference ) )
            ->status->toBe( CartPaymentSession::STATUS_SUPERSEDED )
            ->superseded_at->not->toBeNull()
            ->and( $cart->refresh()->payment_reference )->toBeNull();
    } );

    it( 'cancels the replaced session where the gateway can, and supersedes it where it can\'t', function (): void {
        $cart  = readyCart( $this->product );
        $first = $this->checkout->createPaymentSession( $cart );

        strandedRaisePrice( $this->product );
        $second = $this->checkout->createPaymentSession( $cart );

        expect( $second->reference )->not->toBe( $first->reference )
            ->and( $this->gateway->canceled )->toBe( [ $first->reference ] )
            ->and( strandedRow( $first->reference, 'fake' )->status )->toBe( CartPaymentSession::STATUS_CANCELED )
            ->and( strandedRow( $second->reference, 'fake' ) )->status->toBe( CartPaymentSession::STATUS_ACTIVE )->amount->toBe( 3_500 );

        $other = readyCart( checkoutProduct() );
        $this->checkout->setPaymentGateway( $other, 'plain' );
        $old = $this->checkout->createPaymentSession( $other );
        ProductPrice::query()->update( [ 'price_amount' => 4_000 ] );
        $new = $this->checkout->createPaymentSession( $other );

        expect( strandedRow( $old->reference, 'plain' )->status )->toBe( CartPaymentSession::STATUS_SUPERSEDED )
            ->and( $other->refresh()->payment_reference )->toBe( $new->reference );
    } );

    it( 'stores no card data', function (): void {
        expect( ( new CartPaymentSession() )->getFillable() )->not->toContain( 'card_number' )
            ->and( implode( ',', ( new CartPaymentSession() )->getFillable() ) )->not->toMatch( '/card|cvv|cvc|pan\b/' );

        $this->artisan( 'ecommerce:lint:pci-columns' )->assertSuccessful();
    } );
} );

describe( 'createPaymentSession', function (): void {
    it( 'never replaces a session the provider reports confirmed, and leaves the cart unchanged', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );

        strandedRaisePrice( $this->product );

        strandedExpectCode( fn () => $this->checkout->createPaymentSession( $cart ), 'payment-already-confirmed' );
        strandedExpectCode( fn () => $this->checkout->createPaymentSession( $cart ), 'payment-already-confirmed' );

        expect( $cart->refresh()->payment_reference )->toBe( $session->reference )
            ->and( collect( $this->gateway->calls )->filter( fn ( string $call ): bool => 'createPaymentSession' === $call )->count() )->toBe( 1 )
            ->and( $this->gateway->canceled )->toBe( [] )
            ->and( strandedRow( $session->reference ) )
            ->status->toBe( CartPaymentSession::STATUS_STRANDED )
            ->reason->toBe( StrandedPaymentService::REASON_REPLACEMENT_REFUSED )
            ->and( $this->stranded )->toBe( [ [ 'cart' => $cart->id, 'reference' => $session->reference, 'reason' => StrandedPaymentService::REASON_REPLACEMENT_REFUSED ] ] )
            ->and( Order::query()->count() )->toBe( 0 );
    } );

    it( 'answers payment-already-confirmed with a 409', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference );
        strandedRaisePrice( $this->product );

        try {
            $this->checkout->createPaymentSession( $cart );
            $this->fail( 'Expected a CheckoutException.' );
        } catch ( ArtisanPackUI\Ecommerce\Exceptions\CheckoutException $exception ) {
            expect( $exception->httpStatus() )->toBe( 409 )
                ->and( $exception->field )->toBe( 'payment_reference' );
        }
    } );

    it( 'refuses when the shopper confirms the old session while it is being cancelled', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );

        $this->gateway->confirmBeforeCancel = PaymentSession::STATUS_SUCCEEDED;

        strandedExpectCode( fn () => $this->checkout->createPaymentSession( $cart ), 'payment-already-confirmed' );

        expect( $cart->refresh()->payment_reference )->toBe( $session->reference )
            ->and( strandedRow( $session->reference )->status )->toBe( CartPaymentSession::STATUS_STRANDED );
    } );

    it( 'reuses a confirmed session that still matches the total', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference );

        expect( $this->checkout->createPaymentSession( $cart )->reference )->toBe( $session->reference )
            ->and( $this->stranded )->toBe( [] );
    } );
} );

describe( 'finalize after the total changed', function (): void {
    it( 'holds a captured session for review by default', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );

        strandedExpectCode( fn () => $this->checkout->finalize( $cart, $session->reference ), 'totals-changed' );

        $order = Order::query()->sole();
        $entry = OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'payment.stranded' )->sole();

        expect( $order )
            ->system_status->toBe( 'pending' )
            ->payment_status->toBe( 'paid' )
            ->payment_reference->toBe( $session->reference )
            ->total_amount->toBe( 3_500 )
            ->and( $order->meta['payment_mismatch'] )->toMatchArray( [
                'reason'    => StrandedPaymentService::REASON_TOTALS_CHANGED,
                'reference' => $session->reference,
                'gateway'   => 'fake',
                'paid'      => 2_500,
                'expected'  => 3_500,
                'currency'  => 'USD',
                'captured'  => true,
            ] )
            ->and( $entry->payload )->toMatchArray( [ 'reference' => $session->reference, 'paid' => '2500', 'expected' => '3500', 'placed' => true ] )
            ->and( strandedRow( $session->reference ) )->status->toBe( CartPaymentSession::STATUS_HELD )->order_id->toBe( $order->id )
            ->and( $cart->refresh()->completed_order_id )->toBe( $order->id )
            ->and( $this->gateway->refunds )->toBe( [] )
            ->and( $this->stranded )->toBe( [ [ 'cart' => $cart->id, 'reference' => $session->reference, 'reason' => StrandedPaymentService::REASON_TOTALS_CHANGED ] ] );

        // A repeat finalize returns the held order and settles nothing again.
        expect( $this->checkout->finalize( $cart, $session->reference )->order->id )->toBe( $order->id )
            ->and( Order::query()->count() )->toBe( 1 )
            ->and( $this->stranded )->toHaveCount( 1 );
    } );

    it( 'holds an authorized session without pinning it to the order', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_AUTHORIZED );

        strandedExpectCode( fn () => $this->checkout->finalize( $cart, $session->reference ), 'totals-changed' );

        $order = Order::query()->sole();

        expect( $order )
            ->payment_status->toBe( 'pending' )
            ->payment_reference->toBeNull()
            ->and( $order->meta['payment_mismatch'] )->toMatchArray( [ 'reference' => $session->reference, 'captured' => false ] )
            ->and( $this->gateway->voids )->toBe( [] );
    } );

    it( 'refunds a captured session under the refund policy', function (): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );

        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );

        strandedExpectCode( fn () => $this->checkout->finalize( $cart, $session->reference ), 'totals-changed' );

        expect( Order::query()->count() )->toBe( 0 )
            ->and( $this->gateway->refunds )->toHaveCount( 1 )
            ->and( $this->gateway->refunds[0] )->toMatchArray( [ 'reference' => $session->reference, 'amount' => 2_500 ] )
            ->and( $this->gateway->refunds[0]['context'] )->toBe( [ 'idempotency_key' => 'ap-ec-stranded-' . $session->reference ] )
            ->and( strandedRow( $session->reference ) )->status->toBe( CartPaymentSession::STATUS_REFUNDED )->resolved_at->not->toBeNull()
            ->and( $cart->refresh()->payment_reference )->toBeNull()
            ->and( $cart->checkout_state )->toBe( CheckoutState::PAYMENT_SELECTION )
            ->and( $this->stranded )->toHaveCount( 1 );

        // The shopper can pay the new total; the refunded session isn't reported as stranded.
        expect( $this->checkout->strandedPayment( $cart ) )->toBeNull()
            ->and( $this->checkout->createPaymentSession( $cart )->amount->getAmount() )->toBe( '3500' );
    } );

    it( 'voids an authorized session under the refund policy', function (): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );

        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_AUTHORIZED );

        strandedExpectCode( fn () => $this->checkout->finalize( $cart, $session->reference ), 'totals-changed' );

        expect( $this->gateway->voids )->toBe( [ $session->reference ] )
            ->and( strandedRow( $session->reference )->status )->toBe( CartPaymentSession::STATUS_VOIDED )
            ->and( Order::query()->count() )->toBe( 0 );
    } );

    it( 'keeps a refund or void with an unknown outcome stranded, never a paid hold, and retries it', function ( string $status, string $failure ): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );
        $this->gateway->{$failure} = true;

        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->gateway->confirm( $session->reference, $status );

        strandedExpectCode( fn () => $this->checkout->finalize( $cart, $session->reference ), 'totals-changed' );

        expect( strandedRow( $session->reference ) )
            ->status->toBe( CartPaymentSession::STATUS_STRANDED )
            ->release_attempted_at->not->toBeNull()
            ->and( Order::query()->count() )->toBe( 0 );

        // The provider recovers; even after the store switches to hold, the
        // retry repeats the release instead of holding the payment as paid.
        $this->gateway->{$failure} = false;
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'hold' );

        expect( $this->reconciler->reconcile( 'fake', $session->reference, WebhookResult::OUTCOME_SUCCEEDED ) )->toBe( PaymentReconciler::STRANDED )
            ->and( strandedRow( $session->reference )->status )->toBeIn( [ CartPaymentSession::STATUS_REFUNDED, CartPaymentSession::STATUS_VOIDED ] )
            ->and( Order::query()->count() )->toBe( 0 );
    } )->with( [
        'refund refused' => [ PaymentSession::STATUS_SUCCEEDED, 'refundsFail' ],
        'void refused'   => [ PaymentSession::STATUS_AUTHORIZED, 'voidsFail' ],
    ] );

    it( 'replays a refund whose response was lost, with the same request, moving money once', function (): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );
        $this->gateway->refundsLoseResponse = true;

        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );

        strandedExpectCode( fn () => $this->checkout->finalize( $cart, $session->reference ), 'totals-changed' );

        expect( strandedRow( $session->reference )->status )->toBe( CartPaymentSession::STATUS_STRANDED )
            ->and( Order::query()->count() )->toBe( 0 );

        $this->reconciler->reconcile( 'fake', $session->reference, WebhookResult::OUTCOME_SUCCEEDED );

        expect( $this->gateway->refunds )->toHaveCount( 1 )
            ->and( $this->gateway->refunds[0]['order_id'] )->toBeNull()
            ->and( strandedRow( $session->reference ) )->status->toBe( CartPaymentSession::STATUS_REFUNDED )->resolution_reference->toBe( 're_ap-ec-stranded-' . $session->reference );
    } );

    it( 'holds the session when the gateway doesn\'t take refunds at all', function (): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );
        $this->gateway->refundsSupported = false;

        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );

        strandedExpectCode( fn () => $this->checkout->finalize( $cart, $session->reference ), 'totals-changed' );

        expect( strandedRow( $session->reference ) )->status->toBe( CartPaymentSession::STATUS_HELD )->release_attempted_at->toBeNull()
            ->and( $this->gateway->refunds )->toBe( [] )
            ->and( Order::query()->sole()->meta['payment_mismatch']['reference'] )->toBe( $session->reference );
    } );

    it( 'records the payment as stranded when it can be neither refunded nor held', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );

        // The cart can't become an order any more.
        $cart->items()->delete();

        $row = app( StrandedPaymentService::class )->handle( $cart, $this->gateway->retrievePaymentSession( $session->reference ), StrandedPaymentService::REASON_TOTALS_CHANGED );

        expect( $row->status )->toBe( CartPaymentSession::STATUS_STRANDED )
            ->and( $row->stranded_at )->not->toBeNull()
            ->and( Order::query()->count() )->toBe( 0 )
            ->and( $this->stranded )->toHaveCount( 1 )
            ->and( $this->checkout->strandedPayment( $cart )?->reference )->toBe( $session->reference );
    } );

    it( 'cancels an unconfirmed session at the provider and drops it as before', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );

        strandedExpectCode( fn () => $this->checkout->finalize( $cart, $session->reference ), 'totals-changed' );

        expect( Order::query()->count() )->toBe( 0 )
            ->and( $this->gateway->canceled )->toBe( [ $session->reference ] )
            ->and( strandedRow( $session->reference )->status )->toBe( CartPaymentSession::STATUS_CANCELED )
            ->and( $cart->refresh()->payment_reference )->toBeNull()
            ->and( $this->stranded )->toBe( [] );
    } );

    it( 'settles a replaced session the shopper confirmed in an old tab', function (): void {
        $cart  = readyCart( $this->product );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $first = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->checkout->createPaymentSession( $cart );
        $this->plain->confirm( $first->reference, PaymentSession::STATUS_SUCCEEDED );

        strandedExpectCode( fn () => $this->checkout->finalize( $cart, $first->reference ), 'payment-reference-mismatch' );

        expect( strandedRow( $first->reference ) )->status->toBe( CartPaymentSession::STATUS_HELD )->reason->toBe( StrandedPaymentService::REASON_SUPERSEDED )
            ->and( Order::query()->sole()->meta['payment_mismatch']['paid'] )->toBe( 2_500 );
    } );

    it( 'ignores another cart\'s session reference', function (): void {
        $other        = readyCart( checkoutProduct() );
        $otherSession = $this->checkout->createPaymentSession( $other );
        $this->gateway->confirm( $otherSession->reference );

        $cart = readyCart( $this->product );
        $this->checkout->createPaymentSession( $cart );

        strandedExpectCode( fn () => $this->checkout->finalize( $cart, $otherSession->reference ), 'payment-reference-mismatch' );

        expect( Order::query()->count() )->toBe( 0 )
            ->and( $this->stranded )->toBe( [] );
    } );
} );

describe( 'reconciler', function (): void {
    it( 'matches a superseded session through the history and holds it', function (): void {
        $cart  = readyCart( $this->product );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $first = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $second = $this->checkout->createPaymentSession( $cart );
        $this->plain->confirm( $first->reference, PaymentSession::STATUS_SUCCEEDED );

        expect( $this->reconciler->reconcile( 'plain', $first->reference, WebhookResult::OUTCOME_SUCCEEDED ) )->toBe( PaymentReconciler::STRANDED );

        $order = Order::query()->sole();

        expect( $order->payment_status )->toBe( 'paid' )
            ->and( $order->payment_reference )->toBe( $first->reference )
            ->and( $order->meta['payment_mismatch'] )->toMatchArray( [ 'paid' => 2_500, 'expected' => 3_500, 'reason' => StrandedPaymentService::REASON_SUPERSEDED ] )
            ->and( strandedRow( $second->reference )->status )->toBe( CartPaymentSession::STATUS_ACTIVE )
            ->and( $this->stranded )->toBe( [ [ 'cart' => $cart->id, 'reference' => $first->reference, 'reason' => StrandedPaymentService::REASON_SUPERSEDED ] ] );

        // A redelivered webhook settles nothing twice.
        expect( $this->reconciler->reconcile( 'plain', $first->reference, WebhookResult::OUTCOME_SUCCEEDED ) )->toBe( PaymentReconciler::SKIPPED )
            ->and( Order::query()->count() )->toBe( 1 )
            ->and( $this->stranded )->toHaveCount( 1 );
    } );

    it( 'refunds a superseded session under the refund policy', function (): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );

        $cart  = readyCart( $this->product );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $first = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $second = $this->checkout->createPaymentSession( $cart );
        $this->plain->confirm( $first->reference, PaymentSession::STATUS_SUCCEEDED );

        expect( $this->reconciler->reconcile( 'plain', $first->reference, WebhookResult::OUTCOME_SUCCEEDED ) )->toBe( PaymentReconciler::STRANDED )
            ->and( $this->plain->refunds )->toHaveCount( 1 )
            ->and( $this->plain->refunds[0]['reference'] )->toBe( $first->reference )
            ->and( Order::query()->count() )->toBe( 0 )
            // The cart keeps its current session for the new total.
            ->and( $cart->refresh()->payment_reference )->toBe( $second->reference );
    } );

    it( 'settles a webhook for the current session confirmed for the old amount', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );

        expect( $this->reconciler->reconcile( 'fake', $session->reference, WebhookResult::OUTCOME_SUCCEEDED ) )->toBe( PaymentReconciler::STRANDED )
            ->and( strandedRow( $session->reference )->status )->toBe( CartPaymentSession::STATUS_HELD )
            ->and( Order::query()->sole()->payment_status )->toBe( 'paid' );
    } );

    it( 'notes a duplicate payment on the order the cart already became', function (): void {
        $cart  = readyCart( $this->product );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $first = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $second = $this->checkout->createPaymentSession( $cart );
        $this->plain->confirm( $second->reference );
        $order = $this->checkout->finalize( $cart, $second->reference )->order;

        $this->plain->confirm( $first->reference, PaymentSession::STATUS_SUCCEEDED );

        expect( $this->reconciler->reconcile( 'plain', $first->reference, WebhookResult::OUTCOME_SUCCEEDED ) )->toBe( PaymentReconciler::STRANDED )
            ->and( Order::query()->count() )->toBe( 1 )
            ->and( strandedRow( $first->reference ) )->status->toBe( CartPaymentSession::STATUS_HELD )->order_id->toBe( $order->id )
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'payment.stranded' )->sole()->payload['placed'] )->toBeFalse()
            ->and( $order->refresh()->payment_reference )->toBe( $second->reference );
    } );

    it( 'only flags a session that is still processing, and settles it once it succeeds', function (): void {
        $cart  = readyCart( $this->product );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $first = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->checkout->createPaymentSession( $cart );

        $this->plain->confirm( $first->reference, PaymentSession::STATUS_PROCESSING );
        $this->reconciler->reconcile( 'plain', $first->reference, WebhookResult::OUTCOME_SUCCEEDED );

        expect( strandedRow( $first->reference )->status )->toBe( CartPaymentSession::STATUS_STRANDED )
            ->and( Order::query()->count() )->toBe( 0 )
            ->and( $this->checkout->strandedPayment( $cart )?->reference )->toBe( $first->reference );

        $this->plain->confirm( $first->reference, PaymentSession::STATUS_SUCCEEDED );
        $this->reconciler->reconcile( 'plain', $first->reference, WebhookResult::OUTCOME_SUCCEEDED );

        expect( strandedRow( $first->reference )->status )->toBe( CartPaymentSession::STATUS_HELD )
            ->and( Order::query()->count() )->toBe( 1 )
            ->and( $this->stranded )->toHaveCount( 1 );
    } );

    it( 'marks a superseded session cancelled only when the provider confirms the cancellation', function (): void {
        $cart  = readyCart( $this->product );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $first = $this->checkout->createPaymentSession( $cart );
        $this->checkout->setPaymentGateway( $cart, 'fake' );

        // A declined card: the session waits for another payment method and
        // stays open (and polled).
        $this->reconciler->reconcile( 'plain', $first->reference, WebhookResult::OUTCOME_FAILED );

        expect( strandedRow( $first->reference )->status )->toBe( CartPaymentSession::STATUS_SUPERSEDED );

        $this->plain->confirm( $first->reference, PaymentSession::STATUS_CANCELED );
        $this->reconciler->reconcile( 'plain', $first->reference, WebhookResult::OUTCOME_FAILED );

        expect( strandedRow( $first->reference )->status )->toBe( CartPaymentSession::STATUS_CANCELED );
    } );

    it( 'closes a flagged (stranded) session once the provider cancels it', function (): void {
        $cart  = readyCart( $this->product );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $first = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->checkout->createPaymentSession( $cart );

        $this->plain->confirm( $first->reference, PaymentSession::STATUS_PROCESSING );
        $this->reconciler->reconcile( 'plain', $first->reference, WebhookResult::OUTCOME_SUCCEEDED );

        expect( strandedRow( $first->reference )->status )->toBe( CartPaymentSession::STATUS_STRANDED );

        $this->plain->confirm( $first->reference, PaymentSession::STATUS_CANCELED );
        $this->reconciler->reconcile( 'plain', $first->reference, WebhookResult::OUTCOME_FAILED );

        expect( strandedRow( $first->reference )->status )->toBe( CartPaymentSession::STATUS_CANCELED );
    } );
} );

describe( 'checkout and webhook race', function (): void {
    it( 'settles the session once when the webhook wins', function ( string $policy ): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', $policy );

        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );

        $this->reconciler->reconcile( 'fake', $session->reference, WebhookResult::OUTCOME_SUCCEEDED );

        try {
            $this->checkout->finalize( $cart, $session->reference );
        } catch ( CartOperationException ) {
            // The old tab is told the payment didn't place an order.
        }

        $this->reconciler->reconcile( 'fake', $session->reference, WebhookResult::OUTCOME_SUCCEEDED );

        expect( Order::query()->count() )->toBe( 'hold' === $policy ? 1 : 0 )
            ->and( $this->gateway->refunds )->toHaveCount( 'refund' === $policy ? 1 : 0 )
            ->and( $this->stranded )->toHaveCount( 1 );
    } )->with( [ 'hold', 'refund' ] );

    it( 'settles the session once when the checkout wins', function ( string $policy ): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', $policy );

        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );

        strandedExpectCode( fn () => $this->checkout->finalize( $cart, $session->reference ), 'totals-changed' );

        expect( $this->reconciler->reconcile( 'fake', $session->reference, WebhookResult::OUTCOME_SUCCEEDED ) )->toBe( PaymentReconciler::SKIPPED )
            ->and( Order::query()->count() )->toBe( 'hold' === $policy ? 1 : 0 )
            ->and( $this->gateway->refunds )->toHaveCount( 'refund' === $policy ? 1 : 0 )
            ->and( $this->stranded )->toHaveCount( 1 );
    } )->with( [ 'hold', 'refund' ] );

    it( 'leaves a session another request already settled alone', function (): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );

        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );
        $service = app( StrandedPaymentService::class );

        $first  = $service->handle( $cart, $this->gateway->retrievePaymentSession( $session->reference ), StrandedPaymentService::REASON_TOTALS_CHANGED );
        $second = $service->handle( $cart, $this->gateway->retrievePaymentSession( $session->reference ), StrandedPaymentService::REASON_SUPERSEDED );

        expect( $first->id )->toBe( $second->id )
            ->and( $second->status )->toBe( CartPaymentSession::STATUS_REFUNDED )
            ->and( $second->reason )->toBe( StrandedPaymentService::REASON_TOTALS_CHANGED )
            ->and( $this->gateway->refunds )->toHaveCount( 1 )
            ->and( $this->stranded )->toHaveCount( 1 );
    } );
} );

describe( 'strandedPayment', function (): void {
    it( 'is null for a cart with nothing stranded', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );

        expect( $this->checkout->strandedPayment( $cart ) )->toBeNull();

        $this->gateway->confirm( $session->reference );

        expect( $this->checkout->strandedPayment( $cart ) )->toBeNull();

        $this->checkout->finalize( $cart, $session->reference );

        expect( $this->checkout->strandedPayment( $cart ) )->toBeNull()
            ->and( $this->stranded )->toBe( [] );
    } );

    it( 'finds the current session confirmed for an old total and records it', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );
        strandedRaisePrice( $this->product );
        app( ArtisanPackUI\Ecommerce\Services\StorefrontCartService::class )->recalculate( $cart );

        $found = $this->checkout->strandedPayment( $cart );

        expect( $found?->reference )->toBe( $session->reference )
            ->and( $found?->amount->getAmount() )->toBe( '2500' )
            ->and( strandedRow( $session->reference ) )->status->toBe( CartPaymentSession::STATUS_STRANDED )->reason->toBe( StrandedPaymentService::REASON_TOTALS_CHANGED )
            ->and( $this->checkout->strandedPayment( $cart )?->reference )->toBe( $session->reference )
            ->and( $this->stranded )->toHaveCount( 1 );
    } );

    it( 'finds a superseded session the provider reports confirmed', function (): void {
        $cart  = readyCart( $this->product );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $first = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->checkout->createPaymentSession( $cart );
        $this->plain->confirm( $first->reference, PaymentSession::STATUS_SUCCEEDED );

        expect( $this->checkout->strandedPayment( $cart )?->reference )->toBe( $first->reference )
            ->and( strandedRow( $first->reference )->reason )->toBe( StrandedPaymentService::REASON_SUPERSEDED );
    } );

    it( 'still reports a payment held for review', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );

        strandedExpectCode( fn () => $this->checkout->finalize( $cart, $session->reference ), 'totals-changed' );

        expect( $this->checkout->strandedPayment( $cart ) )
            ->reference->toBe( $session->reference )
            ->status->toBe( PaymentSession::STATUS_SUCCEEDED );
    } );
} );

describe( 'policy', function (): void {
    it( 'defaults to hold and treats unknown values as hold', function (): void {
        $service = app( StrandedPaymentService::class );

        expect( $service->policy() )->toBe( StrandedPaymentService::POLICY_HOLD );

        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );
        expect( $service->policy() )->toBe( StrandedPaymentService::POLICY_REFUND );

        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'shred' );
        expect( $service->policy() )->toBe( StrandedPaymentService::POLICY_HOLD );
    } );

    it( 'is a store setting admins can change', function (): void {
        app( ArtisanPackUI\Ecommerce\Settings\SettingsRepository::class )->update( 'checkout', [ 'checkout.stranded_payments' => 'refund' ] );

        expect( app( StrandedPaymentService::class )->policy() )->toBe( StrandedPaymentService::POLICY_REFUND );
    } );
} );

describe( 'reconcile command', function (): void {
    it( 'settles a superseded session the provider confirmed without a webhook', function (): void {
        $cart  = readyCart( $this->product );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $first = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->checkout->createPaymentSession( $cart );
        $this->plain->confirm( $first->reference, PaymentSession::STATUS_SUCCEEDED );

        CartPaymentSession::query()->update( [ 'updated_at' => Carbon::now()->subMinutes( 20 ) ] );

        $this->artisan( 'ecommerce:reconcile-payments' )
            ->expectsOutputToContain( 'Handled 1 stranded payment(s); 0 still need settling.' )
            ->assertSuccessful();

        expect( strandedRow( $first->reference )->status )->toBe( CartPaymentSession::STATUS_HELD )
            ->and( Order::query()->count() )->toBe( 1 );
    } );

    it( 'settles a current session confirmed for an old total after the cart left payment_pending', function (): void {
        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );

        // A cart change moves it back to payment selection, keeping the session.
        strandedRaisePrice( $this->product );
        Cart::query()->whereKey( $cart->id )->update( [ 'checkout_state' => CheckoutState::PAYMENT_SELECTION ] );
        CartPaymentSession::query()->update( [ 'updated_at' => Carbon::now()->subMinutes( 20 ) ] );

        $this->artisan( 'ecommerce:reconcile-payments' )->assertSuccessful();

        expect( strandedRow( $session->reference )->status )->toBe( CartPaymentSession::STATUS_HELD );
    } );
} );

/**
 * A cart whose captured (or authorized) session was held for review after
 * its total changed, with 10 units in stock. Returns the held order.
 */
function strandedHold( $test, string $status ): Order
{
    ArtisanPackUI\Ecommerce\Models\InventoryItem::factory()->create( [ 'stockable_type' => $test->product->getMorphClass(), 'stockable_id' => $test->product->id, 'quantity_on_hand' => 10 ] );

    $cart    = readyCart( $test->product );
    $session = $test->checkout->createPaymentSession( $cart );
    strandedRaisePrice( $test->product );
    $test->gateway->confirm( $session->reference, $status );

    strandedExpectCode( fn () => $test->checkout->finalize( $cart, $session->reference ), 'totals-changed' );

    return Order::query()->sole();
}

describe( 'resolving a hold', function (): void {
    beforeEach( function (): void {
        $this->service  = app( StrandedPaymentService::class );
        $this->resolved = [];
        $this->paid     = 0;

        addAction( 'ap.ecommerce.checkout.strandedPaymentResolved', function ( Order $order, string $resolution ): void {
            $this->resolved[] = [ $order->id, $resolution ];
        } );
        addAction( 'ap.ecommerce.order.paid', function (): void {
            ++$this->paid;
        } );
    } );

    it( 'accepts an authorized hold: captures, pins the payment, commits stock, marks paid', function (): void {
        $order = strandedHold( $this, PaymentSession::STATUS_AUTHORIZED );
        $item  = ArtisanPackUI\Ecommerce\Models\InventoryItem::query()->sole();

        expect( $item->quantity_reserved )->toBe( 1 );

        $accepted  = $this->service->acceptHold( $order, 7 );
        $reference = $order->meta['payment_mismatch']['reference'];

        expect( $this->gateway->calls )->toContain( 'capturePayment' )
            ->and( $accepted )
            ->payment_status->toBe( 'paid' )
            ->system_status->toBe( 'processing' )
            ->payment_reference->toBe( $reference )
            ->and( $accepted->meta['payment_mismatch'] )->toMatchArray( [ 'captured' => true, 'resolution' => 'accepted' ] )
            ->and( $accepted->meta['payment']['capture_reference'] )->toBe( 'ch_' . $reference )
            ->and( $item->refresh() )->quantity_reserved->toBe( 0 )->quantity_on_hand->toBe( 9 )
            ->and( OrderTimelineEntry::query()->where( 'event_type', 'payment.stranded_accepted' )->sole()->actor_user_id )->toBe( 7 )
            ->and( strandedRow( $reference )->status )->toBe( CartPaymentSession::STATUS_COMPLETED )
            ->and( $this->paid )->toBe( 1 )
            ->and( $this->resolved )->toBe( [ [ $order->id, 'accepted' ] ] );
    } );

    it( 'accepts a captured hold without capturing again', function (): void {
        $order = strandedHold( $this, PaymentSession::STATUS_SUCCEEDED );

        $accepted = $this->service->acceptHold( $order );

        expect( $this->gateway->calls )->not->toContain( 'capturePayment' )
            ->and( $accepted )->payment_status->toBe( 'paid' )->system_status->toBe( 'processing' )
            ->and( ArtisanPackUI\Ecommerce\Models\InventoryItem::query()->sole()->quantity_on_hand )->toBe( 9 )
            ->and( $this->paid )->toBe( 1 );
    } );

    it( 'rejects a hold by voiding an authorization or refunding a capture', function ( string $status, string $released ): void {
        $order     = strandedHold( $this, $status );
        $reference = $order->meta['payment_mismatch']['reference'];

        $rejected = $this->service->rejectHold( $order );

        expect( $rejected )
            ->payment_status->toBe( 'failed' )
            ->system_status->toBe( 'failed' )
            ->and( $rejected->meta['payment_mismatch'] )->toMatchArray( [ 'resolution' => 'rejected', 'released' => $released ] )
            ->and( ArtisanPackUI\Ecommerce\Models\InventoryItem::query()->sole() )->quantity_reserved->toBe( 0 )->quantity_on_hand->toBe( 10 )
            ->and( OrderTimelineEntry::query()->where( 'event_type', 'payment.stranded_rejected' )->sole()->payload['released'] )->toBe( $released )
            ->and( strandedRow( $reference )->status )->toBe( $released )
            ->and( $this->resolved )->toBe( [ [ $order->id, 'rejected' ] ] );

        if ( CartPaymentSession::STATUS_REFUNDED === $released ) {
            expect( $this->gateway->refunds )->toHaveCount( 1 )
                ->and( $this->gateway->refunds[0] )->toMatchArray( [ 'reference' => $reference, 'amount' => 2_500 ] );
        } else {
            expect( $this->gateway->voids )->toBe( [ $reference ] );
        }
    } )->with( [
        'authorized' => [ PaymentSession::STATUS_AUTHORIZED, CartPaymentSession::STATUS_VOIDED ],
        'captured'   => [ PaymentSession::STATUS_SUCCEEDED, CartPaymentSession::STATUS_REFUNDED ],
    ] );

    it( 'is idempotent, and refuses the opposite resolution afterwards', function (): void {
        $order = strandedHold( $this, PaymentSession::STATUS_AUTHORIZED );

        $this->service->acceptHold( $order );
        $again = $this->service->acceptHold( $order );

        expect( $again->system_status )->toBe( 'processing' )
            ->and( collect( $this->gateway->calls )->filter( fn ( string $call ): bool => 'capturePayment' === $call )->count() )->toBe( 1 )
            ->and( $this->paid )->toBe( 1 )
            ->and( $this->resolved )->toHaveCount( 1 )
            ->and( fn () => $this->service->rejectHold( $order ) )->toThrow( ArtisanPackUI\Ecommerce\Exceptions\PaymentNotAllowedException::class );
    } );

    it( 'rejects only once', function (): void {
        $order = strandedHold( $this, PaymentSession::STATUS_SUCCEEDED );

        $this->service->rejectHold( $order );
        $this->service->rejectHold( $order );

        expect( $this->gateway->refunds )->toHaveCount( 1 )
            ->and( $this->resolved )->toHaveCount( 1 )
            ->and( fn () => $this->service->acceptHold( $order ) )->toThrow( ArtisanPackUI\Ecommerce\Exceptions\PaymentNotAllowedException::class );
    } );

    it( 'leaves the hold alone when the gateway refuses to release the money', function (): void {
        $order                       = strandedHold( $this, PaymentSession::STATUS_SUCCEEDED );
        $this->gateway->refundsFail  = true;

        expect( fn () => $this->service->rejectHold( $order ) )->toThrow( ArtisanPackUI\Ecommerce\Exceptions\PaymentNotAllowedException::class )
            ->and( $order->refresh() )->system_status->toBe( 'pending' )->payment_status->toBe( 'paid' )
            ->and( $order->meta['payment_mismatch'] )->not->toHaveKey( 'resolution' )
            ->and( $this->resolved )->toBe( [] );
    } );

    it( 'refuses an order that isn\'t a stranded hold', function (): void {
        $plain = Order::factory()->create( [ 'system_status' => 'pending', 'payment_status' => 'paid', 'meta' => [ 'fraud_decision' => [ 'verdict' => 'challenge' ] ] ] );

        expect( fn () => $this->service->acceptHold( $plain ) )->toThrow( ArtisanPackUI\Ecommerce\Exceptions\PaymentNotAllowedException::class )
            ->and( fn () => $this->service->rejectHold( $plain ) )->toThrow( ArtisanPackUI\Ecommerce\Exceptions\PaymentNotAllowedException::class )
            ->and( $this->service->isHold( $plain ) )->toBeFalse()
            ->and( $plain->refresh()->payment_status )->toBe( 'paid' );
    } );

    it( 'refuses a hold staff already moved on', function (): void {
        $order = strandedHold( $this, PaymentSession::STATUS_SUCCEEDED );
        Order::query()->whereKey( $order->id )->update( [ 'system_status' => 'cancelled' ] );

        expect( fn () => $this->service->acceptHold( $order ) )->toThrow( ArtisanPackUI\Ecommerce\Exceptions\PaymentNotAllowedException::class );
    } );
} );

it( 'refuses API writes to meta.payment_mismatch', function (): void {
    require_once dirname( __DIR__ ) . '/Api/ApiTestHelpers.php';

    $order = Order::factory()->create( [ 'meta' => [ 'payment_mismatch' => [ 'reference' => 'ps_1', 'gateway' => 'fake' ] ] ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->patchJson( "/api/ecommerce/v1/orders/{$order->id}", [ 'meta' => [ 'payment_mismatch' => null ] ], idem() )
        ->assertStatus( 422 );
    $this->patchJson( "/api/ecommerce/v1/orders/{$order->id}", [ 'meta' => [ 'gift' => true ] ], idem() )->assertOk();

    expect( $order->fresh()->meta['payment_mismatch'] )->toBe( [ 'reference' => 'ps_1', 'gateway' => 'fake' ] );
} );

describe( 'review fixes', function (): void {
    it( 'settles a replaced reference even when the cart has no current session', function (): void {
        $cart  = readyCart( $this->product );
        $first = $this->checkout->createPaymentSession( $cart );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $this->gateway->confirm( $first->reference, PaymentSession::STATUS_SUCCEEDED );

        strandedExpectCode( fn () => $this->checkout->finalize( $cart, $first->reference ), 'payment-session-required' );

        expect( strandedRow( $first->reference, 'fake' ) )->status->toBe( CartPaymentSession::STATUS_HELD )->reason->toBe( StrandedPaymentService::REASON_SUPERSEDED );
    } );

    it( 'keeps a pruned cart\'s sessions matchable', function (): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );

        $cart  = readyCart( $this->product );
        $first = $this->checkout->createPaymentSession( $cart );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        Cart::query()->whereKey( $cart->id )->update( [ 'expires_at' => Carbon::now()->subDay() ] );

        $this->artisan( 'ecommerce:prune-carts' )->expectsOutputToContain( 'Pruned 1' )->assertSuccessful();

        expect( Cart::query()->find( $cart->id ) )->toBeNull()
            ->and( strandedRow( $first->reference, 'fake' )->cart_id )->toBeNull();

        $this->gateway->confirm( $first->reference, PaymentSession::STATUS_SUCCEEDED );

        expect( $this->reconciler->reconcile( 'fake', $first->reference, WebhookResult::OUTCOME_SUCCEEDED ) )->toBe( PaymentReconciler::STRANDED )
            ->and( strandedRow( $first->reference, 'fake' )->status )->toBe( CartPaymentSession::STATUS_REFUNDED )
            ->and( $this->stranded )->toBe( [] );
    } );

    it( 'records a pruned cart\'s confirmed session as stranded under hold', function (): void {
        $cart  = readyCart( $this->product );
        $first = $this->checkout->createPaymentSession( $cart );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $cart->delete();
        $this->gateway->confirm( $first->reference, PaymentSession::STATUS_SUCCEEDED );

        $this->reconciler->reconcile( 'fake', $first->reference, WebhookResult::OUTCOME_SUCCEEDED );

        expect( strandedRow( $first->reference, 'fake' ) )->status->toBe( CartPaymentSession::STATUS_STRANDED )->stranded_at->not->toBeNull()
            ->and( $this->gateway->refunds )->toBe( [] )
            ->and( Order::query()->count() )->toBe( 0 );
    } );

    it( 'leaves the bundled Stripe gateway without CancelsPaymentSessions', function (): void {
        expect( ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeGateway::class )->not->toImplement( ArtisanPackUI\Ecommerce\Contracts\CancelsPaymentSessions::class );
    } );

    it( 'refuses a new session while the current one\'s state is unknown', function (): void {
        $cart = readyCart( $this->product );
        Cart::query()->whereKey( $cart->id )->update( [ 'payment_reference' => 'ps_unreachable' ] );

        strandedExpectCode( fn () => $this->checkout->createPaymentSession( $cart ), 'payment-status-unknown' );

        expect( $cart->refresh()->payment_reference )->toBe( 'ps_unreachable' )
            ->and( $this->gateway->calls )->not->toContain( 'createPaymentSession' );
    } );

    it( 'checks every superseded session, not only the newest few', function (): void {
        $cart = readyCart( $this->product );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $oldest = $this->checkout->createPaymentSession( $cart );

        foreach ( range( 1, 6 ) as $step ) {
            ProductPrice::query()->where( 'priceable_id', $this->product->id )->update( [ 'price_amount' => 2_000 + $step * 100 ] );
            $this->checkout->createPaymentSession( $cart );
        }

        $this->plain->confirm( $oldest->reference, PaymentSession::STATUS_SUCCEEDED );

        expect( CartPaymentSession::query()->where( 'status', CartPaymentSession::STATUS_SUPERSEDED )->count() )->toBe( 6 )
            ->and( $this->checkout->strandedPayment( $cart )?->reference )->toBe( $oldest->reference );
    } );

    it( 'defers a superseded-looking session while a finalize owns it', function (): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );

        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference );
        $outcome = null;

        // The webhook lands while the finalize holds the order `processing`.
        addAction( 'ap.ecommerce.payment.charging', function () use ( &$outcome, $session ): void {
            $outcome ??= $this->reconciler->reconcile( 'fake', $session->reference, WebhookResult::OUTCOME_SUCCEEDED );
        } );

        $order = $this->checkout->finalize( $cart, $session->reference )->order;

        expect( $outcome )->toBe( PaymentReconciler::SKIPPED )
            ->and( $this->gateway->refunds )->toBe( [] )
            ->and( $this->gateway->voids )->toBe( [] )
            ->and( $order->refresh()->payment_status )->toBe( 'paid' )
            ->and( strandedRow( $session->reference )->status )->toBe( CartPaymentSession::STATUS_COMPLETED );
    } );

    it( 'never refunds the payment an order just paid with', function (): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );

        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );
        $outcome = null;

        // The webhook lands after the paid order commits, before the history is marked.
        addAction( 'ap.ecommerce.order.paid', function () use ( &$outcome, $session ): void {
            $outcome ??= $this->reconciler->reconcile( 'fake', $session->reference, WebhookResult::OUTCOME_SUCCEEDED );
        } );

        $order = $this->checkout->finalize( $cart, $session->reference )->order;

        expect( $outcome )->toBe( PaymentReconciler::SKIPPED )
            ->and( $this->gateway->refunds )->toBe( [] )
            ->and( strandedRow( $session->reference ) )->status->toBe( CartPaymentSession::STATUS_COMPLETED )->order_id->toBe( $order->id );
    } );

    it( 'settles a confirmed session on a cart that can no longer become an order', function (): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );

        $cart    = readyCart( $this->product );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );
        $cart->items()->delete();

        expect( $this->reconciler->reconcile( 'fake', $session->reference, WebhookResult::OUTCOME_SUCCEEDED ) )->toBe( PaymentReconciler::STRANDED )
            ->and( strandedRow( $session->reference ) )->status->toBe( CartPaymentSession::STATUS_REFUNDED )->reason->toBe( StrandedPaymentService::REASON_CART_UNPLACEABLE );
    } );

    it( 'records a pre-1.4 reference before the cart lets go of it', function (): void {
        $cart                                 = readyCart( $this->product );
        $this->gateway->sessions['ps_legacy'] = new PaymentSession( gatewayKey: 'fake', reference: 'ps_legacy', amount: new Money\Money( 2_500, new Money\Currency( 'USD' ) ), status: PaymentSession::STATUS_REQUIRES_PAYMENT_METHOD );
        Cart::query()->whereKey( $cart->id )->update( [ 'payment_reference' => 'ps_legacy' ] );

        $this->checkout->setPaymentGateway( $cart, 'plain' );

        expect( strandedRow( 'ps_legacy' ) )->status->toBe( CartPaymentSession::STATUS_SUPERSEDED )->amount->toBeNull()->cart_id->toBe( $cart->id );

        $this->gateway->confirm( 'ps_legacy', PaymentSession::STATUS_SUCCEEDED );
        $this->reconciler->reconcile( 'fake', 'ps_legacy', WebhookResult::OUTCOME_SUCCEEDED );

        expect( strandedRow( 'ps_legacy' ) )->status->toBe( CartPaymentSession::STATUS_HELD )->amount->toBe( 2_500 )->currency->toBe( 'USD' );
    } );

    it( 'records a pre-1.4 reference before a currency change drops it', function (): void {
        $cart = readyCart( checkoutProduct( [ 'USD' => 2_000, 'EUR' => 1_800 ] ) );
        Cart::query()->whereKey( $cart->id )->update( [ 'payment_reference' => 'ps_legacy_fx' ] );
        config()->set( 'artisanpack.ecommerce.currency.enabled', [ 'EUR' ] );

        app( ArtisanPackUI\Ecommerce\Services\StorefrontCartService::class )->changeCurrency( $cart, 'EUR' );

        expect( strandedRow( 'ps_legacy_fx' )->status )->toBe( CartPaymentSession::STATUS_SUPERSEDED );
    } );

    it( 'only steps the cart back when the refunded session is its own (gateway and reference)', function (): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );

        $cart = readyCart( $this->product );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $plain = $this->checkout->createPaymentSession( $cart );
        $this->checkout->setPaymentGateway( $cart, 'fake' );
        $fake = $this->checkout->createPaymentSession( $cart );

        expect( $fake->reference )->toBe( $plain->reference );

        $this->plain->confirm( $plain->reference, PaymentSession::STATUS_SUCCEEDED );
        $this->reconciler->reconcile( 'plain', $plain->reference, WebhookResult::OUTCOME_SUCCEEDED );

        expect( strandedRow( $plain->reference, 'plain' )->status )->toBe( CartPaymentSession::STATUS_REFUNDED )
            ->and( $cart->refresh() )->payment_reference->toBe( $fake->reference )->payment_gateway_key->toBe( 'fake' )
            ->and( $cart->checkout_state )->toBe( CheckoutState::PAYMENT_PENDING );
    } );

    it( 'settles a confirmed session before placing a cart a discount made free', function ( string $policy ): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', $policy );

        $digital = checkoutProduct( [ 'USD' => 1_000 ], [ 'type' => 'digital' ] );
        $cart    = readyCart( $digital );
        $session = $this->checkout->createPaymentSession( $cart );
        $this->gateway->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );
        ProductPrice::query()->where( 'priceable_id', $digital->id )->update( [ 'price_amount' => 0 ] );
        app( ArtisanPackUI\Ecommerce\Services\StorefrontCartService::class )->recalculate( $cart );

        expect( $cart->refresh()->total_amount )->toBe( 0 );

        $order = $this->checkout->finalize( $cart )->order;

        expect( Order::query()->count() )->toBe( 1 );

        if ( 'hold' === $policy ) {
            expect( $order->meta['payment_mismatch'] )->toMatchArray( [ 'paid' => 1_000, 'expected' => 0 ] )
                ->and( strandedRow( $session->reference )->status )->toBe( CartPaymentSession::STATUS_HELD );
        } else {
            expect( $this->gateway->refunds )->toHaveCount( 1 )
                ->and( $order->payment_reference )->toBeNull()
                ->and( strandedRow( $session->reference )->status )->toBe( CartPaymentSession::STATUS_REFUNDED );
        }
    } )->with( [ 'hold', 'refund' ] );

    it( 'reports stranded payments that still need settling', function (): void {
        config()->set( 'artisanpack.ecommerce.checkout.stranded_payments', 'refund' );
        $this->plain->refundsFail = true;

        $cart  = readyCart( $this->product );
        $this->checkout->setPaymentGateway( $cart, 'plain' );
        $first = $this->checkout->createPaymentSession( $cart );
        strandedRaisePrice( $this->product );
        $this->checkout->createPaymentSession( $cart );
        $this->plain->confirm( $first->reference, PaymentSession::STATUS_SUCCEEDED );
        CartPaymentSession::query()->update( [ 'updated_at' => Carbon::now()->subMinutes( 20 ) ] );

        $this->artisan( 'ecommerce:reconcile-payments' )
            ->expectsOutputToContain( 'Handled 1 stranded payment(s); 1 still need settling.' )
            ->assertSuccessful();
    } );
} );

describe( 'resolving a hold, recovery', function (): void {
    it( 'finishes a rejection after a lost void response without voiding twice', function (): void {
        $order                            = strandedHold( $this, PaymentSession::STATUS_AUTHORIZED );
        $service                          = app( StrandedPaymentService::class );
        $this->gateway->voidsLoseResponse = true;

        expect( fn () => $service->rejectHold( $order ) )->toThrow( ArtisanPackUI\Ecommerce\Exceptions\PaymentNotAllowedException::class )
            ->and( $order->refresh()->system_status )->toBe( 'pending' );

        $rejected = $service->rejectHold( $order );

        expect( $rejected->system_status )->toBe( 'failed' )
            ->and( $this->gateway->voids )->toHaveCount( 1 );
    } );

    it( 'sends the same refund request as an automatic stranded refund', function (): void {
        $order     = strandedHold( $this, PaymentSession::STATUS_SUCCEEDED );
        $reference = $order->meta['payment_mismatch']['reference'];

        // An automatic refund already went through at the provider.
        $this->gateway->refundRequests[ 'ap-ec-stranded-' . $reference ] = [ 'reference' => $reference, 'amount' => 2_500, 'order_id' => null ];

        $rejected = app( StrandedPaymentService::class )->rejectHold( $order );

        expect( $rejected->system_status )->toBe( 'failed' )
            ->and( $this->gateway->refunds )->toBe( [] )
            ->and( strandedRow( $reference )->resolution_reference )->toBe( 're_ap-ec-stranded-' . $reference );
    } );
} );
