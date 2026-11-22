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
 *   through the gateway's own `refund()` / `voidPendingPayment()`. When
 *   nothing can be sent (no gateway, or no refund support) the session is
 *   held instead. When a refund or void was sent but not confirmed, it stays
 *   `stranded` and the same request is retried later; it is never held as
 *   paid.
 *
 * Each session is settled at most once: the work runs under the cart's and
 * the session row's locks, and a settled row is left alone, so the
 * storefront's finalize and a provider webhook can race safely. Refunds and
 * voids use an idempotency key per session and always the same request. A
 * session an order owns (a finalize in flight, or its paid order) is never
 * settled here.
 *
 * `ap.ecommerce.checkout.paymentStranded( Cart $cart, PaymentSession
 * $session, string $reason )` fires once per session, on first detection,
 * and not again when it is settled later.
 *
 * Staff resolve a hold with {@see self::acceptHold()} (capture if needed,
 * mark paid, commit stock, move to `processing`) or {@see self::rejectHold()}
 * (void or refund, release stock, move to `failed`), the in-process
 * equivalents of the fraud review's `accept_challenge`. Both fire
 * `ap.ecommerce.checkout.strandedPaymentResolved( Order $order, string
 * $resolution )`.
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
use ArtisanPackUI\Ecommerce\Events\PaymentSucceeded;
use ArtisanPackUI\Ecommerce\Exceptions\PaymentNotAllowedException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartPaymentSession;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
     * Stranded because the cart can no longer become an order (emptied,
     * expired, or its items became unsellable) after the shopper paid.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const REASON_CART_UNPLACEABLE = 'cart_unplaceable';

    /**
     * {@see self::acceptHold()}: staff accepted a held payment.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const RESOLUTION_ACCEPTED = 'accepted';

    /**
     * {@see self::rejectHold()}: staff rejected a held payment.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const RESOLUTION_REJECTED = 'rejected';

    /**
     * Order meta key holding a held payment's details.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const MISMATCH_META_KEY = 'payment_mismatch';

    /**
     * {@see self::release()}: the money was refunded or voided.
     *
     * @since 1.4.0
     *
     * @var string
     */
    protected const RELEASED = 'released';

    /**
     * {@see self::release()}: nothing was sent (no gateway, or no refunds).
     *
     * @since 1.4.0
     *
     * @var string
     */
    protected const RELEASE_UNAVAILABLE = 'unavailable';

    /**
     * {@see self::release()}: a refund or void was sent but not confirmed.
     *
     * @since 1.4.0
     *
     * @var string
     */
    protected const RELEASE_UNKNOWN = 'unknown';

    /**
     * @since 1.4.0
     *
     * @param  OrderPlacementService   $placement  Cart → order, for a hold.
     * @param  PaymentGatewayRegistry  $gateways   Gateways.
     * @param  InventoryService        $inventory  Commits or releases a held order's stock.
     * @param  OrderStatusMachine      $statuses   Moves a resolved hold on.
     */
    public function __construct(
        protected OrderPlacementService $placement,
        protected PaymentGatewayRegistry $gateways,
        protected InventoryService $inventory,
        protected OrderStatusMachine $statuses,
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
     * Marks open sessions for the reference — stranded ones included —
     * cancelled. Call it only once the provider confirms the session is
     * cancelled; settled rows (held, refunded, voided, completed) are left
     * alone.
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
            ->whereIn( 'status', CartPaymentSession::OPEN_STATUSES )
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

            $this->fillFromSession( $row, $session );

            $row->forceFill( [
                'status'      => CartPaymentSession::STATUS_STRANDED,
                'reason'      => $reason,
                'stranded_at' => Carbon::now(),
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
     * Money safety, checked under the cart's and the row's locks:
     *
     * - a session an order owns (its `payment_gateway_key` and
     *   `payment_reference`) is never settled here: a paid order marks the
     *   row `completed`, and a pending or `processing` one (a finalize in
     *   flight) defers it;
     * - a refund or void whose outcome is unknown leaves the row `stranded`
     *   with `release_attempted_at` set. It is never held as paid: the next
     *   pass (webhook, finalize or `ecommerce:reconcile-payments`) repeats
     *   the same request, whatever the policy is by then;
     * - a hold is only the fallback when nothing was sent to the provider
     *   (no gateway, or no refund support).
     *
     * `$cart` is null for a session whose cart was pruned: such a session
     * can be refunded or voided, but not held, and fires no hook.
     *
     * @since 1.4.0
     *
     * @param  Cart|null       $cart     The cart the session was made for (null once pruned).
     * @param  PaymentSession  $session  The confirmed session, freshly retrieved.
     * @param  string          $reason   `self::REASON_*`.
     *
     * @return CartPaymentSession The session's history row, as it ended.
     */
    public function handle( ?Cart $cart, PaymentSession $session, string $reason ): CartPaymentSession
    {
        if ( null !== $cart && ! in_array( $session->status, [ PaymentSession::STATUS_AUTHORIZED, PaymentSession::STATUS_SUCCEEDED ], true ) ) {
            return $this->flag( $cart, $session, $reason );
        }

        [ $row, $first ] = DB::transaction( function () use ( $cart, $session, $reason ): array {
            // The cart first, then the session row: the order every other
            // writer of both takes them in.
            if ( null !== $cart ) {
                Cart::query()->lockForUpdate()->find( $cart->getKey() );
            }

            $row = $this->lockedRow( $cart, $session );

            if ( $row->isResolved() || $this->ownedByOrder( $row, $session ) ) {
                return [ $row, false ];
            }

            $first = ! $row->wasStranded();

            $this->fillFromSession( $row, $session );

            $row->forceFill( [
                'reason'      => $row->reason ?? $reason,
                'stranded_at' => $row->stranded_at ?? Carbon::now(),
            ] );

            if ( ! in_array( $session->status, [ PaymentSession::STATUS_AUTHORIZED, PaymentSession::STATUS_SUCCEEDED ], true ) ) {
                $row->status = CartPaymentSession::STATUS_STRANDED;
                $row->save();

                return [ $row, false ];
            }

            $attempted = null !== $row->release_attempted_at;
            $outcome   = $attempted || self::POLICY_REFUND === $this->policy() ? $this->release( $cart, $row, $session ) : self::RELEASE_UNAVAILABLE;
            $settled   = self::RELEASED === $outcome;

            // Only when nothing reached the provider: an unknown refund or
            // void must never become a paid hold.
            if ( ! $settled && ! $attempted && self::RELEASE_UNKNOWN !== $outcome && null !== $cart ) {
                $settled = $this->hold( $cart, $row, $session, $reason );
            }

            if ( ! $settled ) {
                $row->status = CartPaymentSession::STATUS_STRANDED;

                Log::channel( 'ecommerce' )->critical( self::RELEASE_UNKNOWN === $outcome
                    ? 'A stranded payment\'s refund or void was not confirmed; it will be retried with the same request.'
                    : 'A confirmed payment could not be held or refunded; settle it manually.', [
                        'cart_id'   => $cart?->getKey(),
                        'gateway'   => $session->gatewayKey,
                        'reference' => $session->reference,
                        'amount'    => (int) $session->amount->getAmount(),
                        'currency'  => $session->amount->getCurrency()->getCode(),
                    ] );
            }

            $row->save();

            return [ $row, $first ];
        } );

        if ( $first && null !== $cart ) {
            $this->announce( $cart, $session, $reason );
        }

        return $row;
    }

    /**
     * Whether `$order` is an order placed to hold a stranded payment
     * (`meta.payment_mismatch`), resolved or not.
     *
     * @since 1.4.0
     *
     * @param  Order  $order  Order.
     *
     * @return bool
     */
    public function isHold( Order $order ): bool
    {
        $mismatch = ( (array) ( $order->meta ?? [] ) )[ self::MISMATCH_META_KEY ] ?? null;

        return is_array( $mismatch ) && '' !== (string) ( $mismatch['reference'] ?? '' ) && '' !== (string) ( $mismatch['gateway'] ?? '' );
    }

    /**
     * Staff accept a held stranded payment, as `accept_challenge` accepts a
     * payment held for fraud review: an authorized session is captured
     * through its gateway, the order is marked paid with the session pinned
     * as its payment, its stock reservations are committed, it moves to
     * `processing`, and a `payment.stranded_accepted` timeline entry is
     * written. `ap.ecommerce.payment.succeeded`, `ap.ecommerce.order.paid`
     * and {@see PaymentSucceeded} fire as for a normal capture, then
     * `ap.ecommerce.checkout.strandedPaymentResolved( $order, 'accepted' )`.
     *
     * Runs under the order's row lock. Calling it again on an accepted hold
     * returns the order unchanged.
     *
     * @since 1.4.0
     *
     * @param  Order     $order        A stranded-payment hold.
     * @param  int|null  $actorUserId  Staff user, for the timeline.
     *
     * @throws PaymentNotAllowedException When the order isn't an unresolved hold, the gateway is gone, or the capture fails (nothing changes).
     *
     * @return Order
     */
    public function acceptHold( Order $order, ?int $actorUserId = null ): Order
    {
        [ $order, $result, $changed ] = DB::transaction( function () use ( $order, $actorUserId ): array {
            $locked   = Order::query()->lockForUpdate()->findOrFail( $order->id );
            $mismatch = $this->unresolvedHold( $locked, self::RESOLUTION_ACCEPTED );

            if ( null === $mismatch ) {
                return [ $locked, null, false ];
            }

            [ $gateway, $session ] = $this->heldSession( $locked, $mismatch );

            if ( $session->isCaptured() ) {
                $recorded = ( (array) ( ( (array) ( $locked->meta ?? [] ) )['payment'] ?? [] ) )['capture_reference'] ?? null;
                $result   = PaymentResult::success( $session->amount, (string) ( $recorded ?? $session->metadata['charge'] ?? $session->reference ) );
            } elseif ( PaymentSession::STATUS_AUTHORIZED === $session->status ) {
                $result = $gateway->capturePayment( $this->pinned( $locked, $session ), $session );

                if ( ! $result->success ) {
                    throw new PaymentNotAllowedException( __( 'The held payment for order :order could not be captured: :reason', [
                        'order'  => $locked->order_number,
                        'reason' => (string) ( $result->errorMessage ?? $result->errorCode ?? '' ),
                    ] ), [ 'order_id' => $locked->id ] );
                }
            } else {
                throw new PaymentNotAllowedException( __( 'The held payment for order :order is :status at the provider and can\'t be accepted.', [
                    'order'  => $locked->order_number,
                    'status' => (string) $session->status,
                ] ), [ 'order_id' => $locked->id ] );
            }

            $meta    = (array) ( $locked->meta ?? [] );
            $payment = (array) ( $meta['payment'] ?? [] );

            $payment['capture_reference'] = $payment['capture_reference'] ?? $result->gatewayReference;
            $payment['captured_at']       = $payment['captured_at'] ?? Carbon::now()->toIso8601String();
            $meta['payment']              = $payment;

            $mismatch['captured']            = true;
            $mismatch['resolution']          = self::RESOLUTION_ACCEPTED;
            $mismatch['resolved_at']         = Carbon::now()->toIso8601String();
            $meta[ self::MISMATCH_META_KEY ] = $mismatch;

            $locked->forceFill( [
                'payment_status'      => 'paid',
                'payment_gateway_key' => $session->gatewayKey,
                'payment_reference'   => $session->reference,
                'meta'                => $meta,
            ] )->save();

            OrderTimelineEntry::query()->create( [
                'order_id'      => $locked->id,
                'actor_user_id' => $actorUserId,
                'event_type'    => 'payment.stranded_accepted',
                'payload'       => [
                    'gateway'           => $session->gatewayKey,
                    'reference'         => $session->reference,
                    'amount'            => (string) $result->amount->getAmount(),
                    'currency'          => $result->amount->getCurrency()->getCode(),
                    'gateway_reference' => $result->gatewayReference,
                ],
            ] );

            $this->inventory->commitFor( $locked );
            $this->statuses->transition( $locked, 'processing', $actorUserId, 'payment.stranded_accepted' );

            $this->resolveRow( $session, CartPaymentSession::STATUS_COMPLETED );

            return [ $locked->fresh() ?? $locked, $result, true ];
        } );

        if ( $changed && $result instanceof PaymentResult ) {
            AfterCommit::action( 'ap.ecommerce.payment.succeeded', $result, $order );
            AfterCommit::action( 'ap.ecommerce.order.paid', $order, $result );
            AfterCommit::run( PaymentSucceeded::class, static fn () => Event::dispatch( new PaymentSucceeded( $order, $result ) ) );
            AfterCommit::action( 'ap.ecommerce.checkout.strandedPaymentResolved', $order, self::RESOLUTION_ACCEPTED );
        }

        return $order;
    }

    /**
     * Staff reject a held stranded payment, as a fraud `block` does: the
     * session is voided when it's only authorized, or refunded (in full,
     * idempotency key `ap-ec-stranded-{reference}`) when it was captured;
     * then the order's `payment_status` becomes `failed`, its stock
     * reservations are released, it moves to `failed`, and a
     * `payment.stranded_rejected` timeline entry is written.
     * `ap.ecommerce.checkout.strandedPaymentResolved( $order, 'rejected' )`
     * fires.
     *
     * Unlike the fraud path, the money must be released: when the gateway
     * refuses, nothing changes and it throws. Runs under the order's row
     * lock. Calling it again on a rejected hold returns the order unchanged.
     *
     * @since 1.4.0
     *
     * @param  Order     $order        A stranded-payment hold.
     * @param  int|null  $actorUserId  Staff user, for the timeline.
     *
     * @throws PaymentNotAllowedException When the order isn't an unresolved hold, the gateway is gone, or the refund or void fails (nothing changes).
     *
     * @return Order
     */
    public function rejectHold( Order $order, ?int $actorUserId = null ): Order
    {
        [ $order, $changed ] = DB::transaction( function () use ( $order, $actorUserId ): array {
            $locked   = Order::query()->lockForUpdate()->findOrFail( $order->id );
            $mismatch = $this->unresolvedHold( $locked, self::RESOLUTION_REJECTED );

            if ( null === $mismatch ) {
                return [ $locked, false ];
            }

            [ $gateway, $session ] = $this->heldSession( $locked, $mismatch );

            $released  = null;
            $reference = null;

            try {
                if ( $session->isCaptured() ) {
                    // The same request an automatic stranded refund sends, so
                    // the provider replays it rather than refusing a key reuse.
                    $refund = $gateway->refund( $this->releaseOrder( $session ), $session->amount, null, [ 'idempotency_key' => 'ap-ec-stranded-' . $session->reference ] );

                    if ( $refund->success ) {
                        $released  = CartPaymentSession::STATUS_REFUNDED;
                        $reference = $refund->gatewayReference;
                    }
                } elseif ( ! $session->isCanceled() ) {
                    $gateway->voidPendingPayment( $this->releaseOrder( $session ) );

                    $released = CartPaymentSession::STATUS_VOIDED;
                } else {
                    $released = CartPaymentSession::STATUS_VOIDED;
                }
            } catch ( Throwable $exception ) {
                Log::channel( 'ecommerce' )->warning( 'A held stranded payment could not be released.', [
                    'order_id'  => $locked->id,
                    'reference' => $session->reference,
                    'error'     => $exception->getMessage(),
                ] );
            }

            if ( null === $released ) {
                throw new PaymentNotAllowedException( __( 'The held payment for order :order could not be refunded or voided, so the order was left on hold.', [ 'order' => $locked->order_number ] ), [ 'order_id' => $locked->id ] );
            }

            $meta = (array) ( $locked->meta ?? [] );

            $meta[ self::MISMATCH_META_KEY ]['resolution']  = self::RESOLUTION_REJECTED;
            $meta[ self::MISMATCH_META_KEY ]['released']    = $released;
            $meta[ self::MISMATCH_META_KEY ]['resolved_at'] = Carbon::now()->toIso8601String();

            $locked->forceFill( [ 'payment_status' => 'failed', 'meta' => $meta ] )->save();

            OrderTimelineEntry::query()->create( [
                'order_id'      => $locked->id,
                'actor_user_id' => $actorUserId,
                'event_type'    => 'payment.stranded_rejected',
                'payload'       => [
                    'gateway'           => $session->gatewayKey,
                    'reference'         => $session->reference,
                    'amount'            => (string) $session->amount->getAmount(),
                    'currency'          => $session->amount->getCurrency()->getCode(),
                    'released'          => $released,
                    'gateway_reference' => $reference,
                ],
            ] );

            $this->inventory->releaseFor( $locked );
            $this->statuses->transition( $locked, 'failed', $actorUserId, 'payment.stranded_rejected' );

            $this->resolveRow( $session, $released, $reference );

            return [ $locked->fresh() ?? $locked, true ];
        } );

        if ( $changed ) {
            AfterCommit::action( 'ap.ecommerce.checkout.strandedPaymentResolved', $order, self::RESOLUTION_REJECTED );
        }

        return $order;
    }

    /**
     * The hold's `payment_mismatch` meta when it can still be resolved;
     * `null` when it was already resolved the same way (a repeat call).
     *
     * @since 1.4.0
     *
     * @param  Order   $order       Locked order.
     * @param  string  $resolution  `self::RESOLUTION_*` being applied.
     *
     * @throws PaymentNotAllowedException When the order isn't a hold, was resolved the other way, or is no longer pending.
     *
     * @return array<string, mixed>|null
     */
    protected function unresolvedHold( Order $order, string $resolution ): ?array
    {
        if ( ! $this->isHold( $order ) ) {
            throw new PaymentNotAllowedException( __( 'Order :order isn\'t holding a stranded payment.', [ 'order' => $order->order_number ] ), [ 'order_id' => $order->id ] );
        }

        $mismatch = (array) $order->meta[ self::MISMATCH_META_KEY ];
        $done     = $mismatch['resolution'] ?? null;

        if ( $resolution === $done ) {
            return null;
        }

        if ( null !== $done ) {
            throw new PaymentNotAllowedException( __( 'The held payment for order :order was already :resolution.', [ 'order' => $order->order_number, 'resolution' => (string) $done ] ), [ 'order_id' => $order->id ] );
        }

        if ( 'pending' !== (string) $order->system_status ) {
            throw new PaymentNotAllowedException( __( 'Order :order is :status, so its held payment can no longer be resolved.', [ 'order' => $order->order_number, 'status' => (string) $order->system_status ] ), [ 'order_id' => $order->id ] );
        }

        return $mismatch;
    }

    /**
     * The hold's gateway and its session, freshly retrieved.
     *
     * @since 1.4.0
     *
     * @param  Order                 $order     Locked order.
     * @param  array<string, mixed>  $mismatch  Its `payment_mismatch` meta.
     *
     * @throws PaymentNotAllowedException When the gateway is no longer registered.
     *
     * @return array{0: PaymentGateway, 1: PaymentSession}
     */
    protected function heldSession( Order $order, array $mismatch ): array
    {
        $gateway = $this->gateways->find( (string) $mismatch['gateway'] );

        if ( null === $gateway ) {
            throw new PaymentNotAllowedException( __( 'The payment method of the held payment for order :order is no longer available.', [ 'order' => $order->order_number ] ), [ 'order_id' => $order->id ] );
        }

        return [ $gateway, $gateway->retrievePaymentSession( (string) $mismatch['reference'] ) ];
    }

    /**
     * A copy of `$order` with the held session pinned, in the session's
     * currency, to hand the gateway.
     *
     * @since 1.4.0
     *
     * @param  Order           $order    Order.
     * @param  PaymentSession  $session  Held session.
     *
     * @return Order
     */
    protected function pinned( Order $order, PaymentSession $session ): Order
    {
        $pinned = clone $order;

        $pinned->forceFill( [
            'payment_gateway_key' => $session->gatewayKey,
            'payment_reference'   => $session->reference,
            'currency'            => $session->amount->getCurrency()->getCode(),
        ] );

        return $pinned;
    }

    /**
     * Records how a held session ended in the history.
     *
     * @since 1.4.0
     *
     * @param  PaymentSession  $session    Session.
     * @param  string          $status     `CartPaymentSession::STATUS_*`.
     * @param  string|null     $reference  Provider refund id, if any.
     *
     * @return void
     */
    protected function resolveRow( PaymentSession $session, string $status, ?string $reference = null ): void
    {
        CartPaymentSession::query()
            ->forReference( [ $session->gatewayKey ], $session->reference )
            ->update( array_filter( [
                'status'               => $status,
                'resolution_reference' => $reference,
                'resolved_at'          => Carbon::now(),
                'updated_at'           => Carbon::now(),
            ], static fn ( mixed $value ): bool => null !== $value ) );
    }

    /**
     * Refunds a captured session, or voids an authorized one, through its
     * gateway, with the idempotency key `ap-ec-stranded-{reference}` and the
     * same request every time ({@see self::releaseOrder()}). On success the
     * row is `refunded` / `voided` and a cart still pointing at this very
     * session (same gateway and reference) steps back to payment selection.
     *
     * @since 1.4.0
     *
     * @param  Cart|null           $cart     Cart (locked), or null once pruned.
     * @param  CartPaymentSession  $row      Locked history row.
     * @param  PaymentSession      $session  Session.
     *
     * @return string `self::RELEASED`, `self::RELEASE_UNAVAILABLE` (nothing sent) or `self::RELEASE_UNKNOWN` (sent, not confirmed).
     */
    protected function release( ?Cart $cart, CartPaymentSession $row, PaymentSession $session ): string
    {
        $gateway = $this->gateways->find( $row->gateway_key );

        if ( null === $gateway || ( $session->isCaptured() && ! $gateway->supportsRefunds() ) ) {
            return self::RELEASE_UNAVAILABLE;
        }

        $row->release_attempted_at ??= Carbon::now();

        try {
            if ( $session->isCaptured() ) {
                $refund = $gateway->refund( $this->releaseOrder( $session ), $session->amount, null, [ 'idempotency_key' => 'ap-ec-stranded-' . $session->reference ] );

                if ( ! $refund->success ) {
                    Log::channel( 'ecommerce' )->warning( 'The gateway did not confirm a stranded payment\'s refund; it stays stranded and is retried.', [
                        'cart_id'   => $cart?->getKey(),
                        'gateway'   => $gateway->key(),
                        'reference' => $session->reference,
                        'code'      => $refund->errorCode,
                    ] );

                    return self::RELEASE_UNKNOWN;
                }

                $row->status               = CartPaymentSession::STATUS_REFUNDED;
                $row->resolution_reference = $refund->gatewayReference;
            } else {
                $gateway->voidPendingPayment( $this->releaseOrder( $session ) );

                $row->status = CartPaymentSession::STATUS_VOIDED;
            }
        } catch ( Throwable $exception ) {
            Log::channel( 'ecommerce' )->warning( 'A stranded payment\'s refund or void failed; it stays stranded and is retried.', [
                'cart_id'   => $cart?->getKey(),
                'gateway'   => $gateway->key(),
                'reference' => $session->reference,
                'error'     => $exception->getMessage(),
            ] );

            return self::RELEASE_UNKNOWN;
        }

        $row->resolved_at = Carbon::now();

        if ( null !== $cart ) {
            Cart::query()
                ->whereKey( $cart->getKey() )
                ->whereNull( 'completed_order_id' )
                ->where( 'payment_gateway_key', $session->gatewayKey )
                ->where( 'payment_reference', $session->reference )
                ->update( [ 'payment_reference' => null, 'checkout_state' => CheckoutState::PAYMENT_SELECTION ] );
        }

        return self::RELEASED;
    }

    /**
     * Whether an order owns the session (it is that order's payment): a paid
     * one marks the row `completed`; a pending or `processing` one (a
     * finalize in flight) defers it. Failed or voided orders don't own it.
     *
     * @since 1.4.0
     *
     * @param  CartPaymentSession  $row      Locked history row.
     * @param  PaymentSession      $session  Session.
     *
     * @return bool
     */
    protected function ownedByOrder( CartPaymentSession $row, PaymentSession $session ): bool
    {
        $owner = Order::query()
            ->where( 'payment_gateway_key', $session->gatewayKey )
            ->where( 'payment_reference', $session->reference )
            ->whereIn( 'payment_status', [ 'pending', PaymentOrchestrator::STATUS_PROCESSING, 'paid', 'partially_refunded', 'refunded' ] )
            ->orderByDesc( 'id' )
            ->first();

        if ( null === $owner ) {
            return false;
        }

        if ( in_array( (string) $owner->payment_status, [ 'paid', 'partially_refunded', 'refunded' ], true ) ) {
            $row->forceFill( [ 'status' => CartPaymentSession::STATUS_COMPLETED, 'order_id' => $owner->id, 'resolved_at' => Carbon::now() ] )->save();
        }

        return true;
    }

    /**
     * Copies the provider's amount, currency and status onto the row (a row
     * recorded from a pre-1.4 reference has no amount yet).
     *
     * @since 1.4.0
     *
     * @param  CartPaymentSession  $row      Row.
     * @param  PaymentSession      $session  Session.
     *
     * @return void
     */
    protected function fillFromSession( CartPaymentSession $row, PaymentSession $session ): void
    {
        $row->provider_status = $session->status;
        $row->amount ??= (int) $session->amount->getAmount();
        $row->currency ??= strtoupper( $session->amount->getCurrency()->getCode() );
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
     * The order handed to the gateway to refund or void a stranded session:
     * always an unsaved one carrying only the session's reference, gateway,
     * currency and amount. Automatic releases and {@see self::rejectHold()}
     * send exactly the same request with the same idempotency key, so a
     * retry after a lost response replays the first refund instead of being
     * refused as a changed request.
     *
     * @since 1.4.0
     *
     * @param  PaymentSession  $session  Session.
     *
     * @return Order
     */
    protected function releaseOrder( PaymentSession $session ): Order
    {
        return ( new Order() )->forceFill( [
            'payment_gateway_key' => $session->gatewayKey,
            'payment_reference'   => $session->reference,
            'currency'            => $session->amount->getCurrency()->getCode(),
            'total_amount'        => (int) $session->amount->getAmount(),
            'total_currency'      => $session->amount->getCurrency()->getCode(),
        ] );
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
     * @param  Cart|null       $cart     Cart (null for a pruned cart's existing row).
     * @param  PaymentSession  $session  Session.
     *
     * @return CartPaymentSession
     */
    protected function row( ?Cart $cart, PaymentSession $session ): CartPaymentSession
    {
        /** @var CartPaymentSession */
        return CartPaymentSession::query()->createOrFirst(
            [ 'gateway_key' => $session->gatewayKey, 'reference' => $session->reference ],
            [
                'cart_id'         => $cart?->getKey(),
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
     * @param  Cart|null       $cart     Cart (null for a pruned cart's existing row).
     * @param  PaymentSession  $session  Session.
     *
     * @return CartPaymentSession
     */
    protected function lockedRow( ?Cart $cart, PaymentSession $session ): CartPaymentSession
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
