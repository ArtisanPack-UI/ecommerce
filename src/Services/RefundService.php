<?php

/**
 * RefundService.
 *
 * Issues admin-initiated refunds against a placed {@see Order} — either
 * full or partial — by delegating the money movement to the order's active
 * {@see PaymentGateway} and, on success, writing the ledger rows, updating
 * the order's payment status, restocking the refunded units when requested,
 * and dispatching the {@see OrderRefunded} + {@see PaymentRefunded} events.
 *
 * Provider-declined refunds (gateway returned {@see RefundResult::$success}
 * `false`) surface as a {@see RefundNotAllowedException}: nothing is
 * persisted, no events fire, and the transaction rolls back so the order
 * is left exactly as it was before the attempt.
 *
 * Engine spec §5.6, §7 events #11 + #15.
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
use ArtisanPackUI\Ecommerce\Events\OrderRefunded;
use ArtisanPackUI\Ecommerce\Events\PaymentRefunded;
use ArtisanPackUI\Ecommerce\Exceptions\RefundNotAllowedException;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\RefundItem;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\ValueObjects\Currency as CurrencyVO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RefundService
{
    /**
     * Order payment statuses that permit issuing further refunds.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected const REFUNDABLE_PAYMENT_STATUSES = [
        'paid',
        'partially_refunded',
    ];

    /**
     * @since 1.0.0
     *
     * @param  PaymentGatewayRegistry  $gateways
     * @param  InventoryService        $inventory
     */
    public function __construct(
        protected PaymentGatewayRegistry $gateways,
        protected InventoryService $inventory,
    ) {
    }

    /**
     * Issues a refund against `$order`.
     *
     * `$lines` is a list of per-item directives:
     *
     * - `order_item_id` (int, required)   — the line being refunded.
     * - `quantity`      (int, required)   — units being refunded on that line.
     *                                        `0` makes an amount-only line (a
     *                                        goodwill or shipping refund) that
     *                                        returns no units and cannot restock.
     * - `amount`        (int, required)   — money value allocated to that line,
     *                                        in the order's payment currency.
     * - `restock`       (bool, optional)  — restore `quantity` units to the
     *                                        line's `InventoryItem`. Defaults to false.
     *
     * The gateway is charged the sum of `amount` values; the sum MUST NOT
     * exceed the order's outstanding refundable balance
     * (`total_amount - total_refunded_amount`).
     *
     * @since 1.0.0
     *
     * @param  Order                                                                             $order         Order to refund against.
     * @param  array<int, array{order_item_id:int, quantity:int, amount:int, restock?:bool}>     $lines         Per-line refund directives.
     * @param  int|null                                                                          $actorUserId   Admin user id issuing the refund.
     * @param  string|null                                                                       $reason        Free-form reason forwarded to the gateway + persisted on the ledger row.
     *
     * @throws RefundNotAllowedException When the order state, payload, or gateway rejects the refund.
     * @throws InvalidArgumentException  When `$lines` is malformed.
     *
     * @return Refund
     */
    public function issue(
        Order $order,
        array $lines,
        ?int $actorUserId = null,
        ?string $reason = null,
    ): Refund {
        if ( [] === $lines ) {
            throw new InvalidArgumentException(
                'RefundService::issue() requires at least one refund line.',
            );
        }

        return DB::transaction( function () use ( $order, $lines, $actorUserId, $reason ): Refund {
            $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );
            $locked->load( 'items' );

            $this->guardOrderRefundable( $locked );

            $gateway = $this->resolveGateway( $locked );

            $normalized = $this->normalizeLines( $locked, $lines );

            $refundAmount = array_sum( array_column( $normalized, 'amount' ) );

            $this->guardRefundBalance( $locked, $refundAmount );
            $this->guardPartialRefundCapability( $locked, $gateway, $refundAmount );
            $this->guardPerItemCaps( $locked, $normalized );

            $currency = (string) $locked->currency;
            $money    = new Money(
                (string) $refundAmount,
                CurrencyVO::of( $currency )->toMoneyPhp(),
            );

            /** @var Money $filteredAmount */
            $filteredAmount = applyFilters( 'ap.ecommerce.payment.refunding', $money, $locked, $reason );

            // The filter contract permits subscribers to modify the Money,
            // so re-run every guard the pre-filter amount cleared: currency
            // must still match the order, and the (possibly higher) total
            // must still fit inside the outstanding balance and the
            // partial-refund capability of the active gateway.
            if ( $filteredAmount->getCurrency()->getCode() !== $currency ) {
                throw new RefundNotAllowedException( __( 'Refund filter returned currency ":returned"; order :order is settled in ":currency".', [
                    'returned' => $filteredAmount->getCurrency()->getCode(),
                    'order'    => $locked->id,
                    'currency' => $currency,
                ] ) );
            }

            $filteredTotal = (int) $filteredAmount->getAmount();

            $this->guardRefundBalance( $locked, $filteredTotal );
            $this->guardPartialRefundCapability( $locked, $gateway, $filteredTotal );

            $result = $gateway->refund( $locked, $filteredAmount, $reason );

            if ( ! $result->success ) {
                throw new RefundNotAllowedException( __( 'Gateway ":gateway" declined refund for order :order: :error', [
                    'gateway' => $gateway->key(),
                    'order'   => $locked->id,
                    'error'   => $result->errorMessage ?? ( $result->errorCode ?? __( 'unknown error' ) ),
                ] ) );
            }

            // A gateway that reports success but moved a different amount
            // (partial fulfilment, currency mismatch) would silently leave
            // the local ledger out of sync with provider settlement. Reject
            // that before touching any tables — the ledger row is the audit
            // record for reconciliation, and it MUST match what the
            // provider actually did.
            if (
                ! $result->amount->equals( $filteredAmount )
                || $result->amount->getCurrency()->getCode() !== $filteredAmount->getCurrency()->getCode()
            ) {
                throw new RefundNotAllowedException( __( 'Gateway ":gateway" reported a successful refund of :refunded :refunded_currency but :requested :requested_currency was requested for order :order; refusing to record a mismatched ledger row.', [
                    'gateway'            => $gateway->key(),
                    'refunded'           => $result->amount->getAmount(),
                    'refunded_currency'  => $result->amount->getCurrency()->getCode(),
                    'requested'          => $filteredAmount->getAmount(),
                    'requested_currency' => $filteredAmount->getCurrency()->getCode(),
                    'order'              => $locked->id,
                ] ) );
            }

            $refund = Refund::query()->create( [
                'order_id'          => $locked->id,
                'amount'            => (int) $filteredAmount->getAmount(),
                'currency'          => $filteredAmount->getCurrency()->getCode(),
                'reason'            => null !== $reason ? mb_substr( $reason, 0, 255 ) : null,
                'gateway_reference' => $result->gatewayReference,
                'issued_by_user_id' => $actorUserId,
            ] );

            foreach ( $normalized as $line ) {
                RefundItem::query()->create( [
                    'refund_id'     => $refund->id,
                    'order_item_id' => $line['order_item_id'],
                    'quantity'      => $line['quantity'],
                    'amount'        => $line['amount'],
                    'currency'      => $currency,
                    'restock'       => $line['restock'],
                ] );

                if ( $line['restock'] ) {
                    $this->restockLine( $locked, $line );
                }
            }

            $locked->total_refunded_amount   = (int) $locked->total_refunded_amount + (int) $filteredAmount->getAmount();
            $locked->total_refunded_currency = $currency;
            $locked->payment_status          = $locked->total_refunded_amount >= (int) $locked->total_amount
                ? 'refunded'
                : 'partially_refunded';
            $locked->save();

            OrderTimelineEntry::query()->create( [
                'order_id'      => $locked->id,
                'actor_user_id' => $actorUserId,
                'event_type'    => 'order.refunded',
                'payload'       => [
                    'refund_id'         => $refund->id,
                    'amount'            => (int) $filteredAmount->getAmount(),
                    'currency'          => $currency,
                    'reason'            => $reason,
                    'gateway'           => $gateway->key(),
                    'gateway_reference' => $result->gatewayReference,
                    'payment_status'    => $locked->payment_status,
                ],
            ] );

            $refreshed = $locked->fresh( 'items' ) ?? $locked;

            doAction( 'ap.ecommerce.payment.refunded', $result, $refreshed );
            doAction( 'ap.ecommerce.order.refunded', $refreshed, $refund );

            Event::dispatch( new PaymentRefunded( $refreshed, $result ) );
            Event::dispatch( new OrderRefunded( $refreshed, $refund ) );

            return $refund->fresh( 'items' ) ?? $refund;
        } );
    }

    /**
     * Rejects orders whose current payment state does not permit refunds.
     *
     * @since 1.0.0
     *
     * @param  Order  $order
     *
     * @throws RefundNotAllowedException
     *
     * @return void
     */
    protected function guardOrderRefundable( Order $order ): void
    {
        if ( ! in_array( (string) $order->payment_status, self::REFUNDABLE_PAYMENT_STATUSES, true ) ) {
            throw new RefundNotAllowedException( __( 'Order :order has payment_status ":status"; refunds require one of: :allowed.', [
                'order'   => $order->id,
                'status'  => $order->payment_status,
                'allowed' => implode( ', ', self::REFUNDABLE_PAYMENT_STATUSES ),
            ] ) );
        }
    }

    /**
     * Resolves the {@see PaymentGateway} for `$order` and asserts it can refund.
     *
     * @since 1.0.0
     *
     * @param  Order  $order
     *
     * @throws RefundNotAllowedException
     *
     * @return PaymentGateway
     */
    protected function resolveGateway( Order $order ): PaymentGateway
    {
        $key = (string) ( $order->payment_gateway_key ?? '' );

        if ( '' === $key ) {
            throw new RefundNotAllowedException( __( 'Order :order has no payment_gateway_key set; cannot resolve a gateway to refund through.', [
                'order' => $order->id,
            ] ) );
        }

        if ( ! $this->gateways->has( $key ) ) {
            throw new RefundNotAllowedException( __( 'PaymentGateway ":gateway" for order :order is not registered.', [
                'gateway' => $key,
                'order'   => $order->id,
            ] ) );
        }

        $gateway = $this->gateways->get( $key );

        if ( ! $gateway->supportsRefunds() ) {
            throw new RefundNotAllowedException( __( 'PaymentGateway ":gateway" does not support refunds.', [
                'gateway' => $key,
            ] ) );
        }

        return $gateway;
    }

    /**
     * Normalises and validates the per-line refund payload.
     *
     * @since 1.0.0
     *
     * @param  Order                                                                          $order
     * @param  array<int, array{order_item_id:int, quantity:int, amount:int, restock?:bool}>  $lines
     *
     * @throws InvalidArgumentException  When a line is missing required keys or carries invalid values.
     * @throws RefundNotAllowedException When a referenced order item does not belong to `$order`.
     *
     * @return array<int, array{order_item_id:int, quantity:int, amount:int, restock:bool}>
     */
    protected function normalizeLines( Order $order, array $lines ): array
    {
        $orderItemIds = $order->items->pluck( 'id' )->all();
        $out          = [];

        foreach ( $lines as $line ) {
            foreach ( [ 'order_item_id', 'quantity', 'amount' ] as $required ) {
                if ( ! array_key_exists( $required, $line ) ) {
                    throw new InvalidArgumentException( sprintf(
                        'RefundService refund line is missing required key "%s".',
                        $required,
                    ) );
                }
            }

            $orderItemId = (int) $line['order_item_id'];
            $quantity    = (int) $line['quantity'];
            $amount      = (int) $line['amount'];

            if ( $quantity < 0 ) {
                throw new InvalidArgumentException(
                    'RefundService refund line quantity must be zero or a positive integer.',
                );
            }

            if ( 0 === $quantity && (bool) ( $line['restock'] ?? false ) ) {
                throw new InvalidArgumentException(
                    'RefundService amount-only refund lines (quantity 0) cannot restock.',
                );
            }

            if ( $amount < 1 ) {
                throw new InvalidArgumentException(
                    'RefundService refund line amount must be a positive integer of minor units.',
                );
            }

            if ( ! in_array( $orderItemId, $orderItemIds, true ) ) {
                throw new RefundNotAllowedException( __( 'Order item :item does not belong to order :order.', [
                    'item'  => $orderItemId,
                    'order' => $order->id,
                ] ) );
            }

            $out[] = [
                'order_item_id' => $orderItemId,
                'quantity'      => $quantity,
                'amount'        => $amount,
                'restock'       => (bool) ( $line['restock'] ?? false ),
            ];
        }

        return $out;
    }

    /**
     * Asserts the requested `$refundAmount` fits inside the order's outstanding refundable balance.
     *
     * @since 1.0.0
     *
     * @param  Order  $order
     * @param  int    $refundAmount
     *
     * @throws RefundNotAllowedException
     *
     * @return void
     */
    protected function guardRefundBalance( Order $order, int $refundAmount ): void
    {
        $outstanding = (int) $order->total_amount - (int) $order->total_refunded_amount;

        if ( $refundAmount > $outstanding ) {
            throw new RefundNotAllowedException( __( 'Refund total :total exceeds outstanding refundable balance :outstanding for order :order.', [
                'total'       => $refundAmount,
                'outstanding' => $outstanding,
                'order'       => $order->id,
            ] ) );
        }
    }

    /**
     * Rejects a partial refund when the active gateway only supports full refunds.
     *
     * @since 1.0.0
     *
     * @param  Order           $order
     * @param  PaymentGateway  $gateway
     * @param  int             $refundAmount
     *
     * @throws RefundNotAllowedException
     *
     * @return void
     */
    protected function guardPartialRefundCapability(
        Order $order,
        PaymentGateway $gateway,
        int $refundAmount,
    ): void {
        if ( $gateway->supportsPartialRefunds() ) {
            return;
        }

        $outstanding = (int) $order->total_amount - (int) $order->total_refunded_amount;

        if ( $refundAmount !== $outstanding ) {
            throw new RefundNotAllowedException( __( 'PaymentGateway ":gateway" does not support partial refunds; refund total (:total) must equal the outstanding balance (:outstanding).', [
                'gateway'     => $gateway->key(),
                'total'       => $refundAmount,
                'outstanding' => $outstanding,
            ] ) );
        }
    }

    /**
     * Asserts each line's `quantity` does not exceed what remains unrefunded on that line.
     *
     * @since 1.0.0
     *
     * @param  Order                                                                        $order
     * @param  array<int, array{order_item_id:int, quantity:int, amount:int, restock:bool}> $normalized
     *
     * @throws RefundNotAllowedException
     *
     * @return void
     */
    protected function guardPerItemCaps( Order $order, array $normalized ): void
    {
        $requested = [];
        foreach ( $normalized as $line ) {
            $id                = $line['order_item_id'];
            $requested[ $id ]  = ( $requested[ $id ] ?? 0 ) + $line['quantity'];
        }

        $priorByItem = RefundItem::query()
            ->whereIn( 'order_item_id', array_keys( $requested ) )
            ->selectRaw( 'order_item_id, COALESCE(SUM(quantity), 0) AS refunded' )
            ->groupBy( 'order_item_id' )
            ->pluck( 'refunded', 'order_item_id' )
            ->map( fn ( $v ) => (int) $v )
            ->all();

        $itemsById = $order->items->keyBy( 'id' );

        foreach ( $requested as $itemId => $qty ) {
            /** @var OrderItem $item */
            $item        = $itemsById[ $itemId ];
            $alreadyRfnd = (int) ( $priorByItem[ $itemId ] ?? 0 );
            $remaining   = (int) $item->quantity - $alreadyRfnd;

            if ( $qty > $remaining ) {
                throw new RefundNotAllowedException( __( 'Refund quantity :quantity for order item :item exceeds the :remaining unit(s) still refundable on that line.', [
                    'quantity'  => $qty,
                    'item'      => $itemId,
                    'remaining' => $remaining,
                ] ) );
            }
        }
    }

    /**
     * Adds the refunded quantity back to the line's {@see InventoryItem}, if one exists.
     *
     * Missing or untracked inventory rows are treated as a no-op: refunding
     * a digital/inventory-less product still records the ledger, it just
     * has no stock to restore. Actual stock writes go through the shared
     * {@see InventoryService::adjust()} path so `ap.ecommerce.inventory.*`
     * hooks fire the same way they do for other stock changes.
     *
     * @since 1.0.0
     *
     * @param  Order                                                                     $order
     * @param  array{order_item_id:int, quantity:int, amount:int, restock:bool}          $line
     *
     * @return void
     */
    protected function restockLine( Order $order, array $line ): void
    {
        /** @var OrderItem|null $item */
        $item = $order->items->firstWhere( 'id', $line['order_item_id'] );

        if ( null === $item ) {
            return;
        }

        [ $stockableType, $stockableId ] = null !== $item->product_variant_id
            ? [ ProductVariant::class, (int) $item->product_variant_id ]
            : [ Product::class, (int) $item->product_id ];

        if ( null === $stockableId ) {
            return;
        }

        /** @var InventoryItem|null $inventory */
        $inventory = InventoryItem::query()
            ->where( 'stockable_type', $stockableType )
            ->where( 'stockable_id', $stockableId )
            ->first();

        if ( null === $inventory || ! $inventory->track_inventory ) {
            return;
        }

        $this->inventory->adjust(
            $inventory,
            $line['quantity'],
            sprintf( 'refund.restock:order#%d:item#%d', $order->id, $line['order_item_id'] ),
        );
    }
}
