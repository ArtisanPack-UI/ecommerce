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

            $result = $gateway->refund( $locked, $filteredAmount, $reason );

            if ( ! $result->success ) {
                throw new RefundNotAllowedException( sprintf(
                    'Gateway "%s" declined refund for order %d: %s',
                    $gateway->key(),
                    $locked->id,
                    $result->errorMessage ?? ( $result->errorCode ?? 'unknown error' ),
                ) );
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
            throw new RefundNotAllowedException( sprintf(
                'Order %d has payment_status "%s"; refunds require one of: %s.',
                $order->id,
                $order->payment_status,
                implode( ', ', self::REFUNDABLE_PAYMENT_STATUSES ),
            ) );
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
            throw new RefundNotAllowedException( sprintf(
                'Order %d has no payment_gateway_key set; cannot resolve a gateway to refund through.',
                $order->id,
            ) );
        }

        if ( ! $this->gateways->has( $key ) ) {
            throw new RefundNotAllowedException( sprintf(
                'PaymentGateway "%s" for order %d is not registered.',
                $key,
                $order->id,
            ) );
        }

        $gateway = $this->gateways->get( $key );

        if ( ! $gateway->supportsRefunds() ) {
            throw new RefundNotAllowedException( sprintf(
                'PaymentGateway "%s" does not support refunds.',
                $key,
            ) );
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

            if ( $quantity < 1 ) {
                throw new InvalidArgumentException(
                    'RefundService refund line quantity must be a positive integer.',
                );
            }

            if ( $amount < 1 ) {
                throw new InvalidArgumentException(
                    'RefundService refund line amount must be a positive integer of minor units.',
                );
            }

            if ( ! in_array( $orderItemId, $orderItemIds, true ) ) {
                throw new RefundNotAllowedException( sprintf(
                    'Order item %d does not belong to order %d.',
                    $orderItemId,
                    $order->id,
                ) );
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
            throw new RefundNotAllowedException( sprintf(
                'Refund total %d exceeds outstanding refundable balance %d for order %d.',
                $refundAmount,
                $outstanding,
                $order->id,
            ) );
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
            throw new RefundNotAllowedException( sprintf(
                'PaymentGateway "%s" does not support partial refunds; refund total (%d) must equal the outstanding balance (%d).',
                $gateway->key(),
                $refundAmount,
                $outstanding,
            ) );
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
                throw new RefundNotAllowedException( sprintf(
                    'Refund quantity %d for order item %d exceeds the %d unit(s) still refundable on that line.',
                    $qty,
                    $itemId,
                    $remaining,
                ) );
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
