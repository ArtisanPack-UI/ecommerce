<?php

/**
 * StrandedPaymentService.
 *
 * Keeps a cart's payment session history ({@see CartPaymentSession}) and
 * settles a "stranded" payment: a session the provider confirmed that no
 * order can take, because the cart's total changed after the session was
 * made, or because a newer session replaced it (#231). Without this the
 * money is taken and nothing records it.
 *
 * The `checkout.stranded_payments` policy decides what happens:
 *
 * - `hold` (default) — the order is placed for staff review. It stays
 *   `pending` (the engine's review hold, as for a fraud challenge), its
 *   `payment_status` is `paid` when the session was captured, and its
 *   `meta.payment_mismatch` records both amounts and the session. A
 *   `payment.stranded` timeline entry is written. When the cart already
 *   became an order, the entry is written on that order instead.
 * - `refund` — a captured session is refunded and an authorized one voided
 *   through the gateway's own `refund()` / `voidPendingPayment()`. When the
 *   gateway can't (no refund support, or the provider refuses), the session
 *   is held instead.
 *
 * Each session is settled at most once: the work runs under the cart's and
 * the session row's locks, and a settled row is left alone, so the
 * storefront's finalize and a provider webhook can race safely. Refunds and
 * voids use an idempotency key per session.
 *
 * `ap.ecommerce.checkout.paymentStranded( Cart $cart, PaymentSession
 * $session, string $reason )` fires once per session, the first time it is
 * found stranded (after the policy ran, when it could run).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Checkout\CheckoutState;
use ArtisanPackUI\Ecommerce\Contracts\CancelsPaymentSessions;
use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartPaymentSession;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.4.0
 */
class StrandedPaymentService
{
    /**
     * `checkout.stranded_payments`: place the order for staff review.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const POLICY_HOLD = 'hold';

    /**
     * `checkout.stranded_payments`: refund (or void) the payment.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const POLICY_REFUND = 'refund';

    /**
     * Stranded because the cart's total changed after the session was made.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const REASON_TOTALS_CHANGED = 'totals_changed';

    /**
     * Stranded because the cart's currency changed after the session was made.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const REASON_CURRENCY_CHANGED = 'currency_changed';

    /**
     * Stranded because a newer session replaced it (or the gateway changed).
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const REASON_SUPERSEDED = 'superseded';

    /**
     * Found when a new session was asked for: the current one is confirmed
     * for another total, so it isn't replaced.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const REASON_REPLACEMENT_REFUSED = 'replacement_refused';

    /**
     * Order meta key holding a held payment's details.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const MISMATCH_META_KEY = 'payment_mismatch';

    /**
     * @since 1.4.0
     *
     * @param  OrderPlacementService   $placement  Cart → order, for a hold.
     * @param  PaymentGatewayRegistry  $gateways   Gateways.
     */
    public function __construct(
        protected OrderPlacementService $placement,
        protected PaymentGatewayRegistry $gateways,
    ) {
    }

    /**
     * The `checkout.stranded_payments` policy (`hold` unless set to `refund`).
     *
     * @since 1.4.0
     *
     * @return string
     */
    public function policy(): string
    {
        $policy = (string) ecommerceSetting( 'checkout.stranded_payments', self::POLICY_HOLD );

        return self::POLICY_REFUND === $policy ? self::POLICY_REFUND : self::POLICY_HOLD;
    }

    /**
     * Records `$session` as the cart's current session, creating its history
     * row when it has none (a session made before 1.4.0, or reused).
     *
     * @since 1.4.0
     *
     * @param  Cart            $cart     Cart.
     * @param  PaymentSession  $session  Session.
     *
     * @return CartPaymentSession
     */
    public function record( Cart $cart, PaymentSession $session ): CartPaymentSession
    {
        $row = $this->row( $cart, $session );

        if ( CartPaymentSession::STATUS_SUPERSEDED === $row->status ) {
            $row->status        = CartPaymentSession::STATUS_ACTIVE;
            $row->superseded_at = null;
        }

        $row->provider_status = $session->status ?? $row->provider_status;
        $row->save();

        return $row;
    }

    /**
     * Marks the cart's session `$reference` superseded (when it's still open
     * and not stranded), so it is kept for matching after the cart moves on.
     *
     * @since 1.4.0
     *
     * @param  Cart         $cart        Cart.
     * @param  string|null  $gatewayKey  Gateway the session was made with.
     * @param  string|null  $reference   Session reference.
     *
     * @return void
     */
    public function supersede( Cart $cart, ?string $gatewayKey, ?string $reference ): void
    {
        CartPaymentSession::supersede( (int) $cart->getKey(), $gatewayKey, $reference );
    }

    /**
     * Retires an unconfirmed session the cart is moving away from: cancels
     * it at the provider when the gateway implements
     * {@see CancelsPaymentSessions}, and marks it cancelled (or superseded,
     * when it can't be cancelled) in the history.
     *
     * Returns the session when it turns out to be confirmed after all (the
     * shopper confirmed it in the meantime): it must not be dropped, and the
     * caller treats it as stranded. Otherwise returns `null`.
     *
     * @since 1.4.0
     *
     * @param  Cart            $cart     Cart.
     * @param  PaymentGateway  $gateway  The session's gateway.
     * @param  PaymentSession  $session  The session, as last retrieved.
     *
     * @return PaymentSession|null
     */
    public function retire( Cart $cart, PaymentGateway $gateway, PaymentSession $session ): ?PaymentSession
    {
        if ( $session->isConfirmed() ) {
            return $session;
        }

        $this->row( $cart, $session );

        if ( $session->isCanceled() ) {
            $this->markCanceled( [ $session->gatewayKey ], $session->reference );

            return null;
        }

        if ( ! $gateway instanceof CancelsPaymentSessions ) {
            $this->supersede( $cart, $session->gatewayKey, $session->reference );

            return null;
        }

        try {
            $gateway->cancelPaymentSession( $session->reference );
        } catch ( Throwable $exception ) {
            try {
                $latest = $gateway->retrievePaymentSession( $session->reference );
            } catch ( Throwable ) {
                $latest = null;
            }

            if ( null !== $latest && $latest->isConfirmed() ) {
                return $latest;
            }

            Log::channel( 'ecommerce' )->warning( 'A replaced payment session could not be cancelled at the provider; it stays matchable if it is confirmed later.', [
                'cart_id'   => $cart->getKey(),
                'gateway'   => $gateway->key(),
                'reference' => $session->reference,
                'error'     => $exception->getMessage(),
            ] );

            $this->supersede( $cart, $session->gatewayKey, $session->reference );

            return null;
        }

        $this->markCanceled( [ $session->gatewayKey ], $session->reference );

        return null;
    }

    /**
     * Marks open sessions for the reference cancelled (the provider reported
     * them cancelled or failed).
     *
     * @since 1.4.0
     *
     * @param  array<int, string>  $gatewayKeys  Gateway keys.
     * @param  string              $reference    Session reference.
     *
     * @return void
     */
    public function markCanceled( array $gatewayKeys, string $reference ): void
    {
        CartPaymentSession::query()
            ->forReference( $gatewayKeys, $reference )
            ->whereIn( 'status', [ CartPaymentSession::STATUS_ACTIVE, CartPaymentSession::STATUS_SUPERSEDED ] )
            ->update( [
                'status'          => CartPaymentSession::STATUS_CANCELED,
                'provider_status' => PaymentSession::STATUS_CANCELED,
                'updated_at'      => Carbon::now(),
            ] );
    }

    /**
     * Records that the session paid for `$order` the usual way.
     *
     * @since 1.4.0
     *
     * @param  Order  $order  The paid order.
     *
     * @return void
     */
    public function markCompleted( Order $order ): void
    {
        $reference = (string) ( $order->payment_reference ?? '' );
        $key       = (string) ( $order->payment_gateway_key ?? '' );

        if ( '' === $reference || '' === $key ) {
            return;
        }

        CartPaymentSession::query()
            ->forReference( [ $key ], $reference )
            ->whereNotIn( 'status', CartPaymentSession::RESOLVED_STATUSES )
            ->update( [
                'status'      => CartPaymentSession::STATUS_COMPLETED,
                'order_id'    => $order->getKey(),
                'resolved_at' => Carbon::now(),
                'updated_at'  => Carbon::now(),
            ] );
    }

    /**
     * The history row for a session reported under one of `$gatewayKeys`.
     *
     * @since 1.4.0
     *
     * @param  array<int, string>  $gatewayKeys  Gateway keys.
     * @param  string              $reference    Session reference.
     *
     * @return CartPaymentSession|null
     */
    public function find( array $gatewayKeys, string $reference ): ?CartPaymentSession
    {
        /** @var CartPaymentSession|null */
        return CartPaymentSession::query()
            ->forReference( $gatewayKeys, $reference )
            ->orderByDesc( 'updated_at' )
            ->orderByDesc( 'id' )
            ->first();
    }

    /**
     * Records that `$session` is stranded without settling it — it is still
     * processing at the provider, or a new session was refused because of
     * it. Fires `ap.ecommerce.checkout.paymentStranded` the first time.
     *
     * @since 1.4.0
     *
     * @param  Cart            $cart     Cart.
     * @param  PaymentSession  $session  Confirmed session.
     * @param  string          $reason   `self::REASON_*`.
     *
     * @return CartPaymentSession
     */
    public function flag( Cart $cart, PaymentSession $session, string $reason ): CartPaymentSession
    {
        [ $row, $first ] = DB::transaction( function () use ( $cart, $session, $reason ): array {
            $row = $this->lockedRow( $cart, $session );

            if ( $row->isResolved() || $row->wasStranded() ) {
                return [ $row, false ];
            }

            $row->forceFill( [
                'status'          => CartPaymentSession::STATUS_STRANDED,
                'provider_status' => $session->status,
                'reason'          => $reason,
                'stranded_at'     => Carbon::now(),
            ] )->save();

            return [ $row, true ];
        } );

        if ( $first ) {
            $this->announce( $cart, $session, $reason );
        }

        return $row;
    }

    /**
     * Settles a stranded session by the `checkout.stranded_payments` policy,
     * at most once. A session that isn't settled at the provider yet
     * (`processing`) is only flagged ({@see self::flag()}).
     *
     * @since 1.4.0
     *
     * @param  Cart            $cart     The cart the session was made for.
     * @param  PaymentSession  $session  The confirmed session, freshly retrieved.
     * @param  string          $reason   `self::REASON_*`.
     *
     * @return CartPaymentSession The session's history row, as it ended.
     */
    public function handle( Cart $cart, PaymentSession $session, string $reason ): CartPaymentSession
    {
        if ( ! in_array( $session->status, [ PaymentSession::STATUS_AUTHORIZED, PaymentSession::STATUS_SUCCEEDED ], true ) ) {
            return $this->flag( $cart, $session, $reason );
        }

        [ $row, $first ] = DB::transaction( function () use ( $cart, $session, $reason ): array {
            // The cart first, then the session row: the order every other
            // writer of both takes them in.
            Cart::query()->lockForUpdate()->find( $cart->getKey() );

            $row = $this->lockedRow( $cart, $session );

            if ( $row->isResolved() ) {
                return [ $row, false ];
            }

            $first = ! $row->wasStranded();

            $row->forceFill( [
                'provider_status' => $session->status,
                'reason'          => $row->reason ?? $reason,
                'stranded_at'     => $row->stranded_at ?? Carbon::now(),
            ] );

            $settled = self::POLICY_REFUND === $this->policy() && $this->release( $cart, $row, $session );

            if ( ! $settled ) {
                $settled = $this->hold( $cart, $row, $session, $reason );
            }

            if ( ! $settled ) {
                $row->status = CartPaymentSession::STATUS_STRANDED;

                Log::channel( 'ecommerce' )->critical( 'A confirmed payment could not be held or refunded; settle it manually.', [
                    'cart_id'   => $cart->getKey(),
                    'gateway'   => $session->gatewayKey,
                    'reference' => $session->reference,
                    'amount'    => (int) $session->amount->getAmount(),
                    'currency'  => $session->amount->getCurrency()->getCode(),
                ] );
            }

            $row->save();

            return [ $row, $first ];
        } );

        if ( $first ) {
            $this->announce( $cart, $session, $reason );
        }

        return $row;
    }

    /**
     * Refunds a captured session, or voids an authorized one, through its
     * gateway. On success the row is `refunded` / `voided` and a cart still
     * pointing at the session is stepped back to payment selection.
     *
     * @since 1.4.0
     *
     * @param  Cart                $cart     Cart (locked).
     * @param  CartPaymentSession  $row      Locked history row.
     * @param  PaymentSession      $session  Session.
     *
     * @return bool Whether the money was released.
     */
    protected function release( Cart $cart, CartPaymentSession $row, PaymentSession $session ): bool
    {
        $gateway = $this->gateways->find( $row->gateway_key );

        if ( null === $gateway ) {
            return false;
        }

        $order = $this->orderFor( $cart, $session );

        try {
            if ( $session->isCaptured() ) {
                if ( ! $gateway->supportsRefunds() ) {
                    return false;
                }

                $refund = $gateway->refund( $order, $session->amount, null, [ 'idempotency_key' => 'ap-ec-stranded-' . $session->reference ] );

                if ( ! $refund->success ) {
                    Log::channel( 'ecommerce' )->warning( 'The gateway refused to refund a stranded payment; holding it for review instead.', [
                        'cart_id'   => $cart->getKey(),
                        'gateway'   => $gateway->key(),
                        'reference' => $session->reference,
                        'code'      => $refund->errorCode,
                    ] );

                    return false;
                }

                $row->status               = CartPaymentSession::STATUS_REFUNDED;
                $row->resolution_reference = $refund->gatewayReference;
            } else {
                $gateway->voidPendingPayment( $order );

                $row->status = CartPaymentSession::STATUS_VOIDED;
            }
        } catch ( Throwable $exception ) {
            Log::channel( 'ecommerce' )->warning( 'A stranded payment could not be refunded or voided; holding it for review instead.', [
                'cart_id'   => $cart->getKey(),
                'gateway'   => $gateway->key(),
                'reference' => $session->reference,
                'error'     => $exception->getMessage(),
            ] );

            return false;
        }

        $row->resolved_at = Carbon::now();

        Cart::query()
            ->whereKey( $cart->getKey() )
            ->whereNull( 'completed_order_id' )
            ->where( 'payment_reference', $session->reference )
            ->update( [ 'payment_reference' => null, 'checkout_state' => CheckoutState::PAYMENT_SELECTION ] );

        return true;
    }

    /**
     * Holds the payment for staff review: places the cart's order with the
     * payment recorded on it, or, when the cart already became an order,
     * notes the payment on that order.
     *
     * @since 1.4.0
     *
     * @param  Cart                $cart     Cart (locked).
     * @param  CartPaymentSession  $row      Locked history row.
     * @param  PaymentSession      $session  Session.
     * @param  string              $reason   `self::REASON_*`.
     *
     * @return bool Whether it was held.
     */
    protected function hold( Cart $cart, CartPaymentSession $row, PaymentSession $session, string $reason ): bool
    {
        $existing = $this->convertedOrder( $cart );

        if ( null !== $existing ) {
            $this->timeline( $existing, $session, $reason, false );

            $row->forceFill( [ 'status' => CartPaymentSession::STATUS_HELD, 'order_id' => $existing->id, 'resolved_at' => Carbon::now() ] );

            return true;
        }

        try {
            $order = $this->placement->place( $cart );
        } catch ( Throwable $exception ) {
            Log::channel( 'ecommerce' )->error( 'The order for a stranded payment could not be placed for review.', [
                'cart_id'   => $cart->getKey(),
                'reference' => $session->reference,
                'error'     => $exception->getMessage(),
            ] );

            return false;
        }

        $captured = $session->isCaptured();
        $meta     = (array) ( $order->meta ?? [] );

        $meta[ self::MISMATCH_META_KEY ] = [
            'reason'      => $reason,
            'gateway'     => $session->gatewayKey,
            'reference'   => $session->reference,
            'paid'        => (int) $session->amount->getAmount(),
            'expected'    => (int) $order->total_amount,
            'currency'    => $session->amount->getCurrency()->getCode(),
            'captured'    => $captured,
            'detected_at' => Carbon::now()->toIso8601String(),
        ];

        if ( $captured ) {
            $payment                      = (array) ( $meta['payment'] ?? [] );
            $payment['capture_reference'] = isset( $session->metadata['charge'] ) ? (string) $session->metadata['charge'] : $session->reference;
            $payment['captured_at']       = Carbon::now()->toIso8601String();
            $meta['payment']              = $payment;
        }

        // An uncaptured authorization isn't pinned as the order's payment:
        // the reconciler would void it as an amount mismatch. Staff capture
        // or release it at the provider (the reference is in the meta).
        $order->forceFill( [
            'payment_status'      => $captured ? 'paid' : 'pending',
            'payment_gateway_key' => $session->gatewayKey,
            'payment_reference'   => $captured ? $session->reference : null,
            'meta'                => $meta,
        ] )->save();

        $this->timeline( $order, $session, $reason, true );

        Cart::query()->whereKey( $cart->getKey() )->update( [ 'checkout_state' => $captured ? CheckoutState::COMPLETED : CheckoutState::PAYMENT_PENDING ] );

        $row->forceFill( [ 'status' => CartPaymentSession::STATUS_HELD, 'order_id' => $order->id, 'resolved_at' => Carbon::now() ] );

        return true;
    }

    /**
     * Writes the `payment.stranded` timeline entry.
     *
     * @since 1.4.0
     *
     * @param  Order           $order    Order.
     * @param  PaymentSession  $session  Session.
     * @param  string          $reason   `self::REASON_*`.
     * @param  bool            $placed   Whether the order was placed for this payment.
     *
     * @return void
     */
    protected function timeline( Order $order, PaymentSession $session, string $reason, bool $placed ): void
    {
        OrderTimelineEntry::query()->create( [
            'order_id'      => $order->id,
            'actor_user_id' => null,
            'event_type'    => 'payment.stranded',
            'payload'       => [
                'gateway'   => $session->gatewayKey,
                'reference' => $session->reference,
                'status'    => $session->status,
                'paid'      => (string) $session->amount->getAmount(),
                'expected'  => (string) $order->total_amount,
                'currency'  => $session->amount->getCurrency()->getCode(),
                'reason'    => $reason,
                'placed'    => $placed,
            ],
        ] );
    }

    /**
     * The order to hand the gateway for a refund or void: the cart's order
     * (with the session pinned), or an unsaved one carrying the session's
     * reference, gateway and amount when the cart has none.
     *
     * @since 1.4.0
     *
     * @param  Cart            $cart     Cart.
     * @param  PaymentSession  $session  Session.
     *
     * @return Order
     */
    protected function orderFor( Cart $cart, PaymentSession $session ): Order
    {
        $order = $this->convertedOrder( $cart );
        $order = null === $order ? new Order() : clone $order;

        $order->forceFill( [
            'payment_gateway_key' => $session->gatewayKey,
            'payment_reference'   => $session->reference,
            'currency'            => $session->amount->getCurrency()->getCode(),
        ] );

        if ( ! $order->exists ) {
            $order->forceFill( [
                'email'          => $cart->email,
                'customer_id'    => $cart->customer_id,
                'total_amount'   => (int) $session->amount->getAmount(),
                'total_currency' => $session->amount->getCurrency()->getCode(),
            ] );
        }

        return $order;
    }

    /**
     * The order the cart already became, if any.
     *
     * @since 1.4.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return Order|null
     */
    protected function convertedOrder( Cart $cart ): ?Order
    {
        $orderId = Cart::query()->whereKey( $cart->getKey() )->value( 'completed_order_id' );

        return null === $orderId ? null : Order::query()->find( $orderId );
    }

    /**
     * The session's history row, created when missing.
     *
     * @since 1.4.0
     *
     * @param  Cart            $cart     Cart.
     * @param  PaymentSession  $session  Session.
     *
     * @return CartPaymentSession
     */
    protected function row( Cart $cart, PaymentSession $session ): CartPaymentSession
    {
        /** @var CartPaymentSession */
        return CartPaymentSession::query()->createOrFirst(
            [ 'gateway_key' => $session->gatewayKey, 'reference' => $session->reference ],
            [
                'cart_id'         => $cart->getKey(),
                'amount'          => (int) $session->amount->getAmount(),
                'currency'        => strtoupper( $session->amount->getCurrency()->getCode() ),
                'status'          => CartPaymentSession::STATUS_ACTIVE,
                'provider_status' => $session->status,
            ],
        );
    }

    /**
     * The session's history row under its row lock, created when missing.
     *
     * @since 1.4.0
     *
     * @param  Cart            $cart     Cart.
     * @param  PaymentSession  $session  Session.
     *
     * @return CartPaymentSession
     */
    protected function lockedRow( Cart $cart, PaymentSession $session ): CartPaymentSession
    {
        $id = $this->row( $cart, $session )->getKey();

        /** @var CartPaymentSession */
        return CartPaymentSession::query()->lockForUpdate()->findOrFail( $id );
    }

    /**
     * Fires `ap.ecommerce.checkout.paymentStranded` once the work commits.
     *
     * @since 1.4.0
     *
     * @param  Cart            $cart     Cart.
     * @param  PaymentSession  $session  Session.
     * @param  string          $reason   `self::REASON_*`.
     *
     * @return void
     */
    protected function announce( Cart $cart, PaymentSession $session, string $reason ): void
    {
        $cart->refresh();

        AfterCommit::action( 'ap.ecommerce.checkout.paymentStranded', $cart, $session, $reason );
    }
}
