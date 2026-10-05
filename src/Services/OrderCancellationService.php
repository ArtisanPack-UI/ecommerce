<?php

/**
 * OrderCancellationService.
 *
 * Cancels an order: moves it to `cancelled` through
 * {@see OrderStatusMachine}, releases the inventory reservations held for
 * it, voids a payment that was authorized but never captured, writes an
 * `order.cancelled` timeline entry, and fires `ap.ecommerce.order.cancelling`
 * / `ap.ecommerce.order.cancelled` and {@see OrderCancelled}.
 *
 * Cancelling never moves money back. When the order was paid, the summary
 * reports the amount still owed so the caller can issue it through
 * {@see RefundService::issue()}.
 *
 * Engine spec §7 event #10, §9.3; admin spec §7.3.
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

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Events\OrderCancelled;
use ArtisanPackUI\Ecommerce\Exceptions\OrderNotCancellableException;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
use ArtisanPackUI\Ecommerce\ValueObjects\OrderCancellationSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderCancellationService
{
    /**
     * Payment status an uncaptured payment moves to once it is voided.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PAYMENT_STATUS_VOIDED = 'voided';

    /**
     * Payment statuses that mean money was captured and may be owed back.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected const CAPTURED_PAYMENT_STATUSES = [ 'paid', 'partially_refunded' ];

    /**
     * @since 1.0.0
     *
     * @param  OrderStatusMachine      $statuses   Status machine.
     * @param  InventoryService        $inventory  Inventory service.
     * @param  PaymentGatewayRegistry  $gateways   Payment gateways.
     */
    public function __construct(
        protected OrderStatusMachine $statuses,
        protected InventoryService $inventory,
        protected PaymentGatewayRegistry $gateways,
    ) {
    }

    /**
     * Describes what cancelling `$order` would do, without doing it.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  The order.
     *
     * @return OrderCancellationSummary
     */
    public function assess( Order $order ): OrderCancellationSummary
    {
        $blockedReason = $this->blockedReason( $order );

        return new OrderCancellationSummary(
            $order,
            null === $blockedReason,
            $blockedReason,
            $this->heldReservations( $order ),
            null !== $this->voidableGateway( $order ),
            $this->refundOwed( $order ),
            (string) $order->currency,
        );
    }

    /**
     * Cancels `$order`.
     *
     * Everything runs under a lock on the order row, including voiding an
     * uncaptured payment, so a concurrent capture cannot be overwritten as
     * voided. A failed void aborts the cancel and changes nothing.
     *
     * @since 1.0.0
     *
     * @param  Order     $order    The order.
     * @param  string    $reason   Why it is being cancelled. Required.
     * @param  int|null  $actorId  Auth user id cancelling it; `null` for system.
     *
     * @throws InvalidArgumentException     When `$reason` is blank.
     * @throws OrderNotCancellableException When the order's status does not allow it, or the void fails.
     *
     * @return OrderCancellationSummary What was released, voided, and is still owed.
     */
    public function cancel( Order $order, string $reason, ?int $actorId = null ): OrderCancellationSummary
    {
        $reason = mb_substr( trim( $reason ), 0, 500 );

        if ( '' === $reason ) {
            throw new InvalidArgumentException( __( 'A reason is required to cancel an order.' ) );
        }

        $current = Order::query()->findOrFail( $order->id );
        $blocked = $this->blockedReason( $current );

        if ( null !== $blocked ) {
            throw new OrderNotCancellableException( $blocked, [ 'order_id' => $current->id, 'system_status' => $current->system_status ] );
        }

        doAction( 'ap.ecommerce.order.cancelling', $current, $reason );

        $summary = DB::transaction( function () use ( $current, $reason, $actorId ): OrderCancellationSummary {
            // The order row stays locked from here to commit, so a capture
            // (which also locks it) cannot land between deciding to void and
            // recording the void. The gateway call runs under the lock, as
            // RefundService's does; a failed void rolls everything back.
            // A void that succeeds can't be rolled back, though: if a later
            // step throws, the order stays pending and the retry voids
            // again, which the PaymentGateway contract requires to be a
            // no-op for an already-voided authorization (engine issue #154).
            $locked  = Order::query()->lockForUpdate()->findOrFail( $current->id );
            $blocked = $this->blockedReason( $locked );

            if ( null !== $blocked ) {
                throw new OrderNotCancellableException( $blocked, [ 'order_id' => $locked->id, 'system_status' => $locked->system_status ] );
            }

            $gateway = $this->voidableGateway( $locked );

            if ( null !== $gateway ) {
                try {
                    $gateway->voidPendingPayment( $locked );
                } catch ( Throwable $exception ) {
                    throw new OrderNotCancellableException(
                        __( 'The pending payment on order :order could not be voided: :error', [ 'order' => $locked->order_number, 'error' => $exception->getMessage() ] ),
                        [ 'order_id' => $locked->id, 'gateway' => $gateway->key() ],
                        0,
                        $exception,
                    );
                }

                // Recorded before the status change so listeners on it (the
                // cancellation email, digital revocation) see the voided payment.
                $locked->payment_status = self::PAYMENT_STATUS_VOIDED;
                $locked->save();
            }

            $from     = (string) $locked->system_status;
            $released = $this->inventory->releaseFor( $locked );

            $this->statuses->transition( $locked, 'cancelled', $actorId, $reason );

            $cancelled = Order::query()->findOrFail( $locked->id );
            $owed      = $this->refundOwed( $cancelled );

            OrderTimelineEntry::query()->create( [
                'order_id'      => $cancelled->id,
                'actor_user_id' => $actorId,
                'event_type'    => 'order.cancelled',
                'payload'       => [
                    'from'           => $from,
                    'reason'         => $reason,
                    'released'       => $released,
                    'payment_voided' => null !== $gateway,
                    'refund_owed'    => $owed,
                    'currency'       => (string) $cancelled->currency,
                ],
            ] );

            return new OrderCancellationSummary( $cancelled, true, null, $released, null !== $gateway, $owed, (string) $cancelled->currency );
        } );

        AfterCommit::action( 'ap.ecommerce.order.cancelled', $summary->order );
        Event::dispatch( new OrderCancelled( $summary->order, $reason ) );

        return $summary;
    }

    /**
     * Why `$order` cannot be cancelled, or `null` when it can.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  The order.
     *
     * @return string|null
     */
    protected function blockedReason( Order $order ): ?string
    {
        $status = (string) $order->system_status;

        if ( 'cancelled' === $status ) {
            return __( 'Order :order is already cancelled.', [ 'order' => $order->order_number ] );
        }

        if ( ! $this->statuses->isTransitionAllowed( $status, 'cancelled' ) ) {
            return __( 'Order :order is :status and can no longer be cancelled.', [ 'order' => $order->order_number, 'status' => $status ] );
        }

        return null;
    }

    /**
     * The reservations held for `$order`.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  The order.
     *
     * @return array<int, array{inventory_item_id: int, quantity: int}>
     */
    protected function heldReservations( Order $order ): array
    {
        return InventoryReservation::query()
            ->where( 'reservable_type', $order->getMorphClass() )
            ->where( 'reservable_id', $order->getKey() )
            ->orderBy( 'id' )
            ->get( [ 'inventory_item_id', 'quantity' ] )
            ->map( static fn ( InventoryReservation $reservation ): array => [
                'inventory_item_id' => (int) $reservation->inventory_item_id,
                'quantity'          => (int) $reservation->quantity,
            ] )
            ->all();
    }

    /**
     * The gateway to void through, when the order's payment is still
     * pending at a registered gateway.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  The order.
     *
     * @return PaymentGateway|null
     */
    protected function voidableGateway( Order $order ): ?PaymentGateway
    {
        $key = (string) ( $order->payment_gateway_key ?? '' );

        if ( 'pending' !== $order->payment_status || '' === $key || ! $this->gateways->has( $key ) ) {
            return null;
        }

        return $this->gateways->get( $key );
    }

    /**
     * Minor units captured and not yet refunded.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  The order.
     *
     * @return int
     */
    protected function refundOwed( Order $order ): int
    {
        if ( ! in_array( (string) $order->payment_status, self::CAPTURED_PAYMENT_STATUSES, true ) ) {
            return 0;
        }

        return max( 0, (int) $order->total_amount - (int) $order->total_refunded_amount );
    }
}
