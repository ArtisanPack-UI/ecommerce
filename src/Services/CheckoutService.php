<?php

/**
 * CheckoutService.
 *
 * The engine's checkout building blocks (parent plan §7.2, §7.4; engine spec
 * §6.2, §9.2). Storefronts compose them into their own flow — one page,
 * several steps, or an express button — so no step order is forced beyond
 * what a step needs and the `ap.ecommerce.checkout.canTransitionTo` guard:
 *
 * - {@see self::start()} — marks checkout started, holds the cart's stock
 *   (reducing or removing lines that can't be held), and moves the cart to
 *   `addressing`;
 * - {@see self::setEmail()}, {@see self::setAddress()} — contact and
 *   addresses (starting checkout if needed);
 * - {@see self::shippingRates()}, {@see self::setShippingMethod()} — a rate
 *   re-quoted server-side for the cart's shipping address;
 * - {@see self::availableGateways()}, {@see self::setPaymentGateway()};
 * - {@see self::createPaymentSession()} — the provider session the shopper
 *   confirms client-side; reused while the cart, gateway and amount stay
 *   the same;
 * - {@see self::finalize()} — places the order
 *   ({@see OrderPlacementService}) and captures that same session
 *   ({@see PaymentOrchestrator}). Idempotent per cart: a repeat call returns
 *   (or resumes the payment of) the order already placed.
 *
 * The cart's `checkout_state` follows the plan §7.4 states. Forward moves
 * run the `canTransitionTo` filter; changes that undo an earlier step (a
 * new line after a rate was chosen, a changed total after a payment session
 * was made) move it back without asking.
 *
 * Guest checkout follows `checkout.guest_checkout`: `allowed`,
 * `required_account` (a guest may fill in checkout but needs an account on
 * the cart before paying), or `disabled` (sign in first).
 *
 * Every failure a shopper can fix throws {@see CheckoutException}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Checkout\CheckoutReservations;
use ArtisanPackUI\Ecommerce\Checkout\CheckoutState;
use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Exceptions\CheckoutException;
use ArtisanPackUI\Ecommerce\Exceptions\OrderPlacementException;
use ArtisanPackUI\Ecommerce\Exceptions\PaymentAmountMismatchException;
use ArtisanPackUI\Ecommerce\Exceptions\PaymentCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Exceptions\PaymentInProgressException;
use ArtisanPackUI\Ecommerce\Exceptions\PaymentNotAllowedException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\CheckoutResult;
use ArtisanPackUI\Ecommerce\ValueObjects\CheckoutStart;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentFinalization;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CheckoutService
{
    /**
     * `checkout.guest_checkout`: guests check out freely.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const GUESTS_ALLOWED = 'allowed';

    /**
     * `checkout.guest_checkout`: a guest needs an account before paying.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const GUESTS_REQUIRE_ACCOUNT = 'required_account';

    /**
     * `checkout.guest_checkout`: only signed-in shoppers check out.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const GUESTS_DISABLED = 'disabled';

    /**
     * @since 1.0.0
     *
     * @param  StorefrontCartService  $carts         Cart rules, details, and shipping.
     * @param  CheckoutReservations   $reservations  Stock holds.
     * @param  OrderPlacementService  $placement     Cart → order.
     * @param  PaymentOrchestrator    $payments      Session → assess → capture.
     * @param  PaymentGatewayRegistry $gateways      Gateways.
     * @param  InventoryService       $inventory     Stock commit for free orders.
     * @param  OrderStatusMachine     $statuses      Order status for free orders.
     */
    public function __construct(
        protected StorefrontCartService $carts,
        protected CheckoutReservations $reservations,
        protected OrderPlacementService $placement,
        protected PaymentOrchestrator $payments,
        protected PaymentGatewayRegistry $gateways,
        protected InventoryService $inventory,
        protected OrderStatusMachine $statuses,
    ) {
    }

    /**
     * Starts (or refreshes) checkout: sets `checkout_started_at` the first
     * time (firing `ap.ecommerce.checkout.started`), holds every tracked
     * unit for `checkout.reservation_ttl_minutes` — reducing or removing
     * lines that can't be held in full — and moves a cart that hadn't
     * started to `addressing`.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @throws CheckoutException When the cart is closed, empty, or guests can't check out.
     *
     * @return CheckoutStart
     */
    public function start( Cart $cart ): CheckoutStart
    {
        $this->assertGuestAllowed( $cart, false );

        [ $adjustments, $first ] = DB::transaction( function () use ( $cart ): array {
            $locked = $this->lock( $cart );

            if ( $this->paidLines( $locked )->isEmpty() ) {
                throw OrderPlacementException::emptyCart();
            }

            $first       = null === $locked->checkout_started_at;
            $adjustments = $this->reservations->hold( $locked, true );

            if ( $first ) {
                $locked->checkout_started_at = Carbon::now();
            }

            if ( CheckoutState::NOT_STARTED === $locked->checkout_state ) {
                $this->transition( $locked, CheckoutState::ADDRESSING );
            }

            $locked->save();

            $this->carts->recalculate( $locked );

            return [ $adjustments, $first ];
        } );

        $cart->refresh();

        if ( $first ) {
            doAction( 'ap.ecommerce.checkout.started', $cart );
        }

        return new CheckoutStart( $cart, $adjustments );
    }

    /**
     * Sets the shopper's email (starting checkout if needed).
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart   Cart.
     * @param  string  $email  Email.
     *
     * @throws CheckoutException When the email is invalid or the cart can't check out.
     *
     * @return Cart
     */
    public function setEmail( Cart $cart, string $email ): Cart
    {
        $this->ensureStarted( $cart );

        return $this->carts->updateDetails( $cart, [ 'email' => $email ] );
    }

    /**
     * Sets the shipping and/or billing address (starting checkout if
     * needed) and fires `ap.ecommerce.checkout.addressCaptured` for each.
     * A null billing address means "same as shipping". An order that ships
     * moves on to `shipping_selection`; one that doesn't, to
     * `payment_selection`. A changed shipping address re-quotes a chosen
     * shipping rate (and drops it if it's no longer offered at that price).
     *
     * @since 1.0.0
     *
     * @param  Cart          $cart      Cart.
     * @param  Address|null  $shipping  Shipping address (null for orders that don't ship).
     * @param  Address|null  $billing   Billing address (null: same as shipping).
     *
     * @throws CheckoutException When an address is incomplete or the cart can't check out.
     *
     * @return Cart
     */
    public function setAddress( Cart $cart, ?Address $shipping, ?Address $billing = null ): Cart
    {
        if ( null === $shipping && null === $billing ) {
            throw new CheckoutException( 'shipping_address', 'address-required', __( 'Enter an address.' ) );
        }

        foreach ( [ 'shipping_address' => $shipping, 'billing_address' => $billing ] as $field => $address ) {
            if ( null !== $address && ( '' === trim( $address->address1 ) || '' === trim( $address->city ) ) ) {
                throw new CheckoutException( $field, 'address-incomplete', __( 'Enter the street address and city.' ) );
            }
        }

        $this->ensureStarted( $cart );

        $this->carts->updateDetails( $cart, [
            'shipping_address' => $shipping?->toArray(),
            'billing_address'  => $billing?->toArray(),
        ] );

        DB::transaction( function () use ( $cart ): void {
            $locked = $this->lock( $cart );

            $this->advance( $locked, $this->carts->requiresShipping( $locked ) ? CheckoutState::SHIPPING_SELECTION : CheckoutState::PAYMENT_SELECTION );
            $locked->save();
        } );

        $cart->refresh();

        if ( null !== $shipping ) {
            doAction( 'ap.ecommerce.checkout.addressCaptured', $cart, $shipping, 'shipping' );
        }

        doAction( 'ap.ecommerce.checkout.addressCaptured', $cart, $billing ?? $shipping, 'billing' );

        return $cart;
    }

    /**
     * Shipping rates on offer for the cart's shipping address.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @throws CheckoutException When the cart has no shipping address.
     *
     * @return Collection<int, ShippingRate>
     */
    public function shippingRates( Cart $cart ): Collection
    {
        return $this->carts->quoteShipping( $cart, $this->shippingAddress( $cart ) );
    }

    /**
     * Chooses one of the rates {@see self::shippingRates()} offers (matched
     * by {@see ShippingRate::id()}, re-quoted server-side) and moves on to
     * `payment_selection`. Fires `ap.ecommerce.shipping.methodSelected`.
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart    Cart.
     * @param  string  $rateId  Rate id.
     *
     * @throws CheckoutException When there's no shipping address or the rate isn't offered.
     *
     * @return Cart
     */
    public function setShippingMethod( Cart $cart, string $rateId ): Cart
    {
        $this->carts->selectShippingMethod( $cart, $this->shippingAddress( $cart ), $rateId );

        DB::transaction( function () use ( $cart ): void {
            $locked = $this->lock( $cart );

            $this->advance( $locked, CheckoutState::PAYMENT_SELECTION );
            $locked->save();
        } );

        return $cart->refresh();
    }

    /**
     * The gateways the shopper can pay with: the registry's available
     * gateways for the cart, through `ap.ecommerce.checkout.availableGateways`.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return array<string, PaymentGateway> Keyed by gateway key.
     */
    public function availableGateways( Cart $cart ): array
    {
        $available = $this->gateways->availableFor( $cart );
        $filtered  = applyFilters( 'ap.ecommerce.checkout.availableGateways', collect( $available ), $cart );

        if ( ! $filtered instanceof Collection ) {
            return $available;
        }

        $gateways = [];

        foreach ( $filtered as $gateway ) {
            if ( $gateway instanceof PaymentGateway && isset( $available[ $gateway->key() ] ) ) {
                $gateways[ $gateway->key() ] = $gateway;
            }
        }

        return $gateways;
    }

    /**
     * Chooses the payment gateway. Switching gateways drops a payment
     * session made with the old one.
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart        Cart.
     * @param  string  $gatewayKey  Gateway key.
     *
     * @throws CheckoutException When the gateway isn't available for the cart.
     *
     * @return Cart
     */
    public function setPaymentGateway( Cart $cart, string $gatewayKey ): Cart
    {
        if ( ! isset( $this->availableGateways( $cart )[ $gatewayKey ] ) ) {
            throw new CheckoutException( 'payment_gateway', 'gateway-unavailable', __( 'That payment method isn\'t available.' ) );
        }

        DB::transaction( function () use ( $cart, $gatewayKey ): void {
            $locked = $this->lock( $cart );

            if ( $gatewayKey !== $locked->payment_gateway_key ) {
                $locked->payment_gateway_key = $gatewayKey;
                $locked->payment_reference   = null;

                if ( CheckoutState::PAYMENT_PENDING === $locked->checkout_state ) {
                    $locked->checkout_state = CheckoutState::PAYMENT_SELECTION;
                }
            }

            $locked->save();
        } );

        return $cart->refresh();
    }

    /**
     * Creates — or, for the same gateway and amount, reuses — the provider
     * session the shopper confirms client-side, storing its reference on
     * the cart and moving it to `payment_pending`. The stock holds are
     * refreshed first (a shortfall refuses; call {@see self::start()} to
     * adjust the cart). Fires `ap.ecommerce.checkout.paymentInitiated` for a
     * new session.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart.
     * @param  array<string, mixed>  $context  Provider hints (return URL, idempotency key, …). Admin-only keys are stripped.
     *
     * @throws CheckoutException When the cart isn't ready to pay or needs no payment.
     *
     * @return PaymentSession
     */
    public function createPaymentSession( Cart $cart, array $context = [] ): PaymentSession
    {
        $this->assertGuestAllowed( $cart, true );

        $context = $this->clientContext( $context );

        [ $session, $gateway, $created ] = DB::transaction( function () use ( $cart, $context ): array {
            $locked = $this->lock( $cart );

            $this->carts->recalculate( $locked );
            $locked->refresh();
            $this->assertReadyToPay( $locked );

            if ( (int) $locked->total_amount <= 0 ) {
                throw new CheckoutException( 'payment', 'payment-not-required', __( 'This order needs no payment. Place it directly.' ) );
            }

            $this->reservations->hold( $locked, false );

            $gateway = $this->gateway( $locked );
            $reused  = $this->reusableSession( $locked, $gateway );

            if ( null !== $reused ) {
                $this->advance( $locked, CheckoutState::PAYMENT_PENDING );
                $locked->save();

                return [ $reused, $gateway, false ];
            }

            $this->advance( $locked, CheckoutState::PAYMENT_PENDING );

            $session = $gateway->createPaymentSession( $locked->load( 'items' ), $context );

            $locked->payment_reference = $session->reference;
            $locked->save();

            return [ $session, $gateway, true ];
        } );

        $cart->refresh();

        if ( $created ) {
            doAction( 'ap.ecommerce.checkout.paymentInitiated', $cart, $gateway, $session );
        }

        return $session;
    }

    /**
     * Places the order and captures the payment session the shopper
     * confirmed (parent plan §7.4).
     *
     * Idempotent per cart: once the cart became an order, a repeat call
     * returns that order — resuming its payment if it's still pending (after
     * a 3DS step-up, or to retry a declined capture). Otherwise the session
     * is checked first (it must exist, match `$paymentReference` when given,
     * and be for the cart's current total), then the order is placed and the
     * session captured. A zero-total order is placed and marked paid with no
     * gateway.
     *
     * The cart ends `completed` (paid), `payment_pending` (step-up needed,
     * or a retryable failure), or `failed` (blocked). Fires
     * `ap.ecommerce.checkout.finalized` on success and
     * `ap.ecommerce.checkout.failed` on a terminal failure.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart              Cart.
     * @param  string|null           $paymentReference  Session the shopper confirmed (checked against the cart's).
     * @param  array<string, mixed>  $context           Provider hints and placement context (`ip_address`, `user_agent`, `customer_note`). Admin-only keys are stripped.
     *
     * @throws CheckoutException When the cart can't be finalized.
     *
     * @return CheckoutResult
     */
    public function finalize( Cart $cart, ?string $paymentReference = null, array $context = [] ): CheckoutResult
    {
        $context  = $this->clientContext( $context );
        $existing = $this->placedOrder( $cart );

        if ( null !== $existing ) {
            return $this->resumeOrder( $cart, $existing, $context );
        }

        $this->assertGuestAllowed( $cart, true );

        $cart->refresh();
        $this->assertReadyToPay( $cart );

        if ( (int) $cart->total_amount <= 0 ) {
            return $this->finalizeFree( $cart, $context );
        }

        $reference = (string) ( $cart->payment_reference ?? '' );

        if ( '' === $reference ) {
            throw new CheckoutException( 'payment_reference', 'payment-session-required', __( 'Set up the payment before placing the order.' ) );
        }

        if ( null !== $paymentReference && ! hash_equals( $reference, $paymentReference ) ) {
            throw new CheckoutException( 'payment_reference', 'payment-reference-mismatch', __( 'That payment doesn\'t belong to this cart.' ), 409 );
        }

        $gateway = $this->gateway( $cart );
        $session = $gateway->retrievePaymentSession( $reference );

        if ( $session->isCanceled() ) {
            $this->resetPayment( $cart );

            throw new CheckoutException( 'payment', 'payment-canceled', __( 'The payment was cancelled. Set up the payment again.' ), 409 );
        }

        if ( strtoupper( $session->amount->getCurrency()->getCode() ) !== strtoupper( (string) $cart->currency ) ) {
            $this->resetPayment( $cart );

            throw OrderPlacementException::totalsChanged();
        }

        // The cart as the shopper is buying it, for the fraud assessment
        // (placement empties the cart itself).
        $snapshot = Cart::query()->with( [ 'items.product', 'items.variant' ] )->findOrFail( $cart->getKey() );

        try {
            $order = $this->placement->place( $cart, $context + [ OrderPlacementService::EXPECTED_TOTAL => (int) $session->amount->getAmount() ] );
        } catch ( OrderPlacementException $exception ) {
            if ( 'totals-changed' === $exception->errorCode ) {
                $this->resetPayment( $cart );
            }

            throw $exception;
        }

        return $this->pay( $cart, $order, $snapshot, $context );
    }

    /**
     * The order `$cart` already became, if any.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return Order|null
     */
    public function placedOrder( Cart $cart ): ?Order
    {
        $orderId = Cart::query()->whereKey( $cart->getKey() )->value( 'completed_order_id' );

        return null === $orderId ? null : Order::query()->find( $orderId );
    }

    /**
     * The guest-checkout policy (`checkout.guest_checkout`).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function guestCheckout(): string
    {
        $policy = (string) ecommerceSetting( 'checkout.guest_checkout', self::GUESTS_ALLOWED );

        return in_array( $policy, [ self::GUESTS_ALLOWED, self::GUESTS_REQUIRE_ACCOUNT, self::GUESTS_DISABLED ], true ) ? $policy : self::GUESTS_ALLOWED;
    }

    /**
     * Whether storefronts should offer to create an account at checkout
     * (`checkout.account_creation`).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function offersAccountCreation(): bool
    {
        return (bool) ecommerceSetting( 'checkout.account_creation', false );
    }

    /**
     * Captures the payment for a just-placed order and moves the cart on.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart      Cart (now converted).
     * @param  Order                 $order     Order.
     * @param  Cart                  $snapshot  The cart as it was bought.
     * @param  array<string, mixed>  $context   Provider hints.
     *
     * @throws CheckoutException When the payment can't be attempted.
     *
     * @return CheckoutResult
     */
    protected function pay( Cart $cart, Order $order, Cart $snapshot, array $context ): CheckoutResult
    {
        try {
            $finalization = $this->payments->finalize( $order, $snapshot, $this->assessmentAddress( $order ), $context );
        } catch ( Throwable $exception ) {
            throw $this->paymentFailure( $cart, $exception );
        }

        return $this->settle( $cart, $finalization );
    }

    /**
     * Returns (or resumes the payment of) an order the cart already became.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart.
     * @param  Order                 $order    Order.
     * @param  array<string, mixed>  $context  Provider hints.
     *
     * @throws CheckoutException When the payment can't be resumed.
     *
     * @return CheckoutResult
     */
    protected function resumeOrder( Cart $cart, Order $order, array $context ): CheckoutResult
    {
        if ( in_array( (string) $order->payment_status, [ 'paid', 'partially_refunded', 'refunded' ], true ) || null === $order->payment_reference ) {
            return new CheckoutResult( $order );
        }

        try {
            $finalization = $this->payments->resume( $order, $context );
        } catch ( Throwable $exception ) {
            throw $this->paymentFailure( $cart, $exception );
        }

        return $this->settle( $cart, $finalization );
    }

    /**
     * Moves the cart to the state the payment outcome calls for and fires
     * the finalized / failed hooks.
     *
     * @since 1.0.0
     *
     * @param  Cart                 $cart          Cart.
     * @param  PaymentFinalization  $finalization  Outcome.
     *
     * @return CheckoutResult
     */
    protected function settle( Cart $cart, PaymentFinalization $finalization ): CheckoutResult
    {
        $state = match ( $finalization->status ) {
            PaymentFinalization::STATUS_CAPTURED => CheckoutState::COMPLETED,
            PaymentFinalization::STATUS_BLOCKED  => CheckoutState::FAILED,
            default                              => CheckoutState::PAYMENT_PENDING,
        };

        Cart::query()->whereKey( $cart->getKey() )->update( [ 'checkout_state' => $state ] );
        $cart->refresh();

        if ( $finalization->isCaptured() ) {
            doAction( 'ap.ecommerce.checkout.finalized', $finalization->order, $cart );
        } elseif ( $finalization->isBlocked() ) {
            doAction( 'ap.ecommerce.checkout.failed', $cart, new RuntimeException( 'The payment was blocked.' ) );
        }

        return new CheckoutResult( $finalization->order, $finalization );
    }

    /**
     * Places a zero-total order and marks it paid: no gateway is involved.
     * Its stock is committed and it moves to `processing`.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart.
     * @param  array<string, mixed>  $context  Placement context.
     *
     * @return CheckoutResult
     */
    protected function finalizeFree( Cart $cart, array $context ): CheckoutResult
    {
        $order = DB::transaction( function () use ( $cart, $context ): Order {
            $order = $this->placement->place( $cart, $context + [ OrderPlacementService::EXPECTED_TOTAL => 0 ] );

            $order->forceFill( [ 'payment_status' => 'paid', 'payment_gateway_key' => null, 'payment_reference' => null ] )->save();
            $this->inventory->commitFor( $order );

            return $this->statuses->transition( $order, 'processing', null, 'payment.not_required' );
        } );

        Cart::query()->whereKey( $cart->getKey() )->update( [ 'checkout_state' => CheckoutState::COMPLETED ] );
        $cart->refresh();

        AfterCommit::action( 'ap.ecommerce.checkout.finalized', $order, $cart );

        return new CheckoutResult( $order );
    }

    /**
     * Turns a payment error into a shopper-facing failure; a terminal one
     * also fires `ap.ecommerce.checkout.failed`.
     *
     * @since 1.0.0
     *
     * @param  Cart       $cart       Cart.
     * @param  Throwable  $exception  Error.
     *
     * @return Throwable
     */
    protected function paymentFailure( Cart $cart, Throwable $exception ): Throwable
    {
        return match ( true ) {
            $exception instanceof PaymentInProgressException => new CheckoutException( 'payment', 'payment-in-progress', __( 'Your payment is already being processed.' ), 409 ),
            $exception instanceof PaymentNotAllowedException => new CheckoutException( 'payment', 'payment-not-allowed', __( 'This order can no longer be paid.' ), 409 ),
            $exception instanceof PaymentAmountMismatchException,
            $exception instanceof PaymentCurrencyMismatchException => $this->failed( $cart, $exception, new CheckoutException( 'payment', 'payment-amount-mismatch', __( 'The payment doesn\'t match your order total.' ), 409 ) ),
            default                                                => $exception,
        };
    }

    /**
     * Fires `ap.ecommerce.checkout.failed` and returns `$failure`.
     *
     * @since 1.0.0
     *
     * @param  Cart               $cart       Cart.
     * @param  Throwable          $reason     What went wrong.
     * @param  CheckoutException  $failure    What the shopper is told.
     *
     * @return CheckoutException
     */
    protected function failed( Cart $cart, Throwable $reason, CheckoutException $failure ): CheckoutException
    {
        Cart::query()->whereKey( $cart->getKey() )->update( [ 'checkout_state' => CheckoutState::FAILED ] );

        doAction( 'ap.ecommerce.checkout.failed', $cart, $reason );

        return $failure;
    }

    /**
     * The open cart, locked.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @throws CheckoutException When it became an order or expired.
     *
     * @return Cart
     */
    protected function lock( Cart $cart ): Cart
    {
        $locked = Cart::query()->lockForUpdate()->findOrFail( $cart->getKey() );

        $this->carts->assertOpen( $locked );

        return $locked;
    }

    /**
     * Starts checkout when it hasn't started.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return void
     */
    protected function ensureStarted( Cart $cart ): void
    {
        if ( null === $cart->refresh()->checkout_started_at ) {
            $this->start( $cart );
        }
    }

    /**
     * Moves the cart forward to `$to` (never back), through the
     * `ap.ecommerce.checkout.canTransitionTo` guard.
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart  Locked cart.
     * @param  string  $to    Target state.
     *
     * @throws CheckoutException When a listener blocks the move.
     *
     * @return void
     */
    protected function advance( Cart $cart, string $to ): void
    {
        if ( CheckoutState::rank( (string) $cart->checkout_state ) < CheckoutState::rank( $to ) ) {
            $this->transition( $cart, $to );
        }
    }

    /**
     * Moves the cart to `$to` if `ap.ecommerce.checkout.canTransitionTo`
     * allows it.
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart  Locked cart.
     * @param  string  $to    Target state.
     *
     * @throws CheckoutException When a listener blocks the move.
     *
     * @return void
     */
    protected function transition( Cart $cart, string $to ): void
    {
        if ( $to === $cart->checkout_state ) {
            return;
        }

        if ( true !== applyFilters( 'ap.ecommerce.checkout.canTransitionTo', true, $cart, $to ) ) {
            throw new CheckoutException( 'checkout_state', 'transition-blocked', __( 'This checkout step isn\'t available for your cart.' ), 409 );
        }

        $cart->checkout_state = $to;
    }

    /**
     * Refuses guests the policy doesn't allow at this point.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart       Cart.
     * @param  bool  $atPayment  Whether the shopper is about to pay.
     *
     * @throws CheckoutException When the guest can't continue.
     *
     * @return void
     */
    protected function assertGuestAllowed( Cart $cart, bool $atPayment ): void
    {
        if ( null !== Cart::query()->whereKey( $cart->getKey() )->value( 'customer_id' ) ) {
            return;
        }

        $policy = $this->guestCheckout();

        if ( self::GUESTS_DISABLED === $policy || ( $atPayment && self::GUESTS_REQUIRE_ACCOUNT === $policy ) ) {
            throw new CheckoutException( 'cart', 'account-required', self::GUESTS_DISABLED === $policy
                ? __( 'Sign in to check out.' )
                : __( 'Create an account or sign in to place your order.' ), 403 );
        }
    }

    /**
     * Refuses a cart that can't be paid for yet.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @throws CheckoutException When something is missing.
     *
     * @return void
     */
    protected function assertReadyToPay( Cart $cart ): void
    {
        if ( $this->paidLines( $cart )->isEmpty() ) {
            throw OrderPlacementException::emptyCart();
        }

        if ( null === $cart->email || '' === trim( (string) $cart->email ) ) {
            throw OrderPlacementException::emailRequired();
        }

        if ( $this->carts->requiresShipping( $cart ) ) {
            if ( null === $cart->shipping_address ) {
                throw OrderPlacementException::shippingAddressRequired();
            }

            if ( ! is_array( ( (array) ( $cart->meta ?? [] ) )[ StorefrontCartService::SHIPPING_RATE_META_KEY ] ?? null ) ) {
                throw OrderPlacementException::shippingRateStale();
            }
        }

        if ( null === $cart->billing_address && null === $cart->shipping_address ) {
            throw OrderPlacementException::billingAddressRequired();
        }

        if ( (int) $cart->total_amount > 0 && ( null === $cart->payment_gateway_key || ! isset( $this->availableGateways( $cart )[ (string) $cart->payment_gateway_key ] ) ) ) {
            throw new CheckoutException( 'payment_gateway', 'gateway-required', __( 'Choose a payment method.' ) );
        }
    }

    /**
     * The cart's gateway.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @throws CheckoutException When none is set or it isn't registered.
     *
     * @return PaymentGateway
     */
    protected function gateway( Cart $cart ): PaymentGateway
    {
        $gateway = null === $cart->payment_gateway_key ? null : $this->gateways->find( (string) $cart->payment_gateway_key );

        if ( null === $gateway ) {
            throw new CheckoutException( 'payment_gateway', 'gateway-required', __( 'Choose a payment method.' ) );
        }

        return $gateway;
    }

    /**
     * The cart's session, when it can be reused: same gateway, the cart's
     * currency and current total, and not cancelled. A session the provider
     * can't load is not reused.
     *
     * @since 1.0.0
     *
     * @param  Cart            $cart     Locked cart.
     * @param  PaymentGateway  $gateway  Cart's gateway.
     *
     * @return PaymentSession|null
     */
    protected function reusableSession( Cart $cart, PaymentGateway $gateway ): ?PaymentSession
    {
        $reference = (string) ( $cart->payment_reference ?? '' );

        if ( '' === $reference ) {
            return null;
        }

        try {
            $session = $gateway->retrievePaymentSession( $reference );
        } catch ( Throwable ) {
            return null;
        }

        $matches = ! $session->isCanceled()
            && (int) $session->amount->getAmount() === (int) $cart->total_amount
            && strtoupper( $session->amount->getCurrency()->getCode() ) === strtoupper( (string) $cart->currency );

        return $matches ? $session : null;
    }

    /**
     * Drops the cart's payment session and steps back to payment selection.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return void
     */
    protected function resetPayment( Cart $cart ): void
    {
        Cart::query()->whereKey( $cart->getKey() )->whereNull( 'completed_order_id' )->update( [
            'payment_reference' => null,
            'checkout_state'    => CheckoutState::PAYMENT_SELECTION,
        ] );

        $cart->refresh();
    }

    /**
     * The cart's shipping address.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @throws CheckoutException When it has none.
     *
     * @return Address
     */
    protected function shippingAddress( Cart $cart ): Address
    {
        $address = $cart->refresh()->shipping_address;

        if ( ! is_array( $address ) ) {
            throw OrderPlacementException::shippingAddressRequired();
        }

        return Address::fromArray( $address );
    }

    /**
     * The address a payment is assessed against: shipping, else billing.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return Address
     */
    protected function assessmentAddress( Order $order ): Address
    {
        $address = (array) ( $order->shipping_address ?? $order->billing_address ?? [] );

        if ( 2 !== strlen( (string) ( $address['country_code'] ?? '' ) ) ) {
            $address['country_code'] = (string) config( 'artisanpack.ecommerce.store.country', 'US' );
        }

        return Address::fromArray( $address );
    }

    /**
     * Lines the shopper chose (promotion-granted lines aside).
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return Collection<int, CartItem>
     */
    protected function paidLines( Cart $cart ): Collection
    {
        return $cart->items()->get()->reject( static fn ( CartItem $item ): bool => $item->isFreeItem() )->values();
    }

    /**
     * `$context` without the keys only staff may pass (accepting a payment
     * held for review), so a shopper can't send them.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $context  Context.
     *
     * @return array<string, mixed>
     */
    protected function clientContext( array $context): array
    {
        unset( $context[ PaymentOrchestrator::ACCEPT_CHALLENGE ] );

        return $context;
    }
}
