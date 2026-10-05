<?php

/**
 * RefundService.
 *
 * Issues admin-initiated refunds against a placed {@see Order} — either
 * full or partial — in three steps, so money never moves at the provider
 * without a ledger row that says so:
 *
 * 1. Under the order's row lock the request is validated (refundable
 *    status, balance, per-line quantity and amount caps, the gateway's
 *    partial-refund support) and a `pending` {@see Refund} with its lines is
 *    written. Pending refunds count against the balance, so two concurrent
 *    refunds can't both pass.
 * 2. The gateway is called outside any transaction, with the refund's id as
 *    its idempotency key (a retry of the same refund never moves money
 *    twice, whatever the provider's key window).
 * 3. Under the lock again the refund is settled `succeeded`, the order's
 *    totals and payment status are updated, and refunded units restocked.
 *    The `ap.ecommerce.payment.refunded` / `ap.ecommerce.order.refunded`
 *    actions and the {@see PaymentRefunded} / {@see OrderRefunded} events
 *    fire after commit; a listener that throws is logged, never undoes it.
 *
 * A gateway that declines or errors settles the refund `failed` and
 * surfaces as a {@see RefundNotAllowedException}. A gateway that reports a
 * different amount than requested leaves the refund `pending` (blocking
 * further refunds until someone reconciles it) and throws.
 *
 * Each line's tax and shipping parts are apportioned from the order line
 * and stored with it, so reports can split a refund (audit C8).
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
use ArtisanPackUI\Ecommerce\Inventory\StockLevels;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\RefundItem;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
use ArtisanPackUI\Ecommerce\ValueObjects\Currency as CurrencyVO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Money\Money;
use Throwable;

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
     * @param  StockLevels             $stock     Stock rows a line draws on (bundle members).
     */
    public function __construct(
        protected PaymentGatewayRegistry $gateways,
        protected InventoryService $inventory,
        protected StockLevels $stock,
    ) {
    }

    /**
     * Issues a refund of `$lines` against `$order`.
     *
     * Each line: `order_item_id`, `quantity` (0 for an amount-only refund),
     * `amount` (minor units, the line's share of the refund), optional
     * `restock`.
     *
     * @since 1.0.0
     *
     * @param  Order                             $order        Order.
     * @param  array<int, array<string, mixed>>  $lines        Refund lines.
     * @param  int|null                          $actorUserId  Admin issuing it.
     * @param  string|null                       $reason       Free-text reason.
     *
     * @throws InvalidArgumentException  When a line is malformed.
     * @throws RefundNotAllowedException When the refund isn't allowed or the gateway refuses it.
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

        [ $refund, $gateway, $amount, $normalized ] = $this->openRefund( $order, $lines, $actorUserId, $reason );

        try {
            $result = $gateway->refund( $order, $amount, $reason, [
                'idempotency_key' => 'ap-ec-refund-' . $refund->id,
                'refund_id'       => $refund->id,
            ] );
        } catch ( RefundNotAllowedException $exception ) {
            $this->failRefund( $refund, $gateway, $exception->getMessage() );

            throw $exception;
        } catch ( Throwable $exception ) {
            $this->failRefund( $refund, $gateway, $exception->getMessage() );

            throw new RefundNotAllowedException( __( 'Gateway ":gateway" could not refund order :order: :error', [
                'gateway' => $gateway->key(),
                'order'   => $order->id,
                'error'   => $exception->getMessage(),
            ] ), [], 0, $exception );
        }

        if ( ! $result->success ) {
            $this->failRefund( $refund, $gateway, $result->errorMessage ?? ( $result->errorCode ?? 'unknown error' ) );

            throw new RefundNotAllowedException( __( 'Gateway ":gateway" declined refund for order :order: :error', [
                'gateway' => $gateway->key(),
                'order'   => $order->id,
                'error'   => $result->errorMessage ?? ( $result->errorCode ?? __( 'unknown error' ) ),
            ] ) );
        }

        // A gateway that reports success but moved a different amount would
        // leave the ledger out of step with the provider. The refund stays
        // pending (blocking further refunds) for someone to reconcile.
        if ( ! $result->amount->equals( $amount ) ) {
            OrderTimelineEntry::query()->create( [
                'order_id'      => $order->id,
                'actor_user_id' => $actorUserId,
                'event_type'    => 'refund.amount_mismatch',
                'payload'       => [
                    'refund_id'          => $refund->id,
                    'requested'          => (string) $amount->getAmount(),
                    'refunded'           => (string) $result->amount->getAmount(),
                    'requested_currency' => $amount->getCurrency()->getCode(),
                    'refunded_currency'  => $result->amount->getCurrency()->getCode(),
                    'gateway_reference'  => $result->gatewayReference,
                ],
            ] );

            throw new RefundNotAllowedException( __( 'Gateway ":gateway" reported a successful refund of :refunded :refunded_currency but :requested :requested_currency was requested for order :order; refusing to record a mismatched ledger row.', [
                'gateway'            => $gateway->key(),
                'refunded'           => $result->amount->getAmount(),
                'refunded_currency'  => $result->amount->getCurrency()->getCode(),
                'requested'          => $amount->getAmount(),
                'requested_currency' => $amount->getCurrency()->getCode(),
                'order'              => $order->id,
            ] ) );
        }

        $refreshed = DB::transaction( function () use ( $order, $refund, $result, $normalized, $gateway, $actorUserId, $reason, $amount ): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );
            $locked->load( 'items' );

            $refund->settle( Refund::STATUS_SUCCEEDED, $result->gatewayReference );

            foreach ( $normalized as $line ) {
                if ( $line['restock'] ) {
                    $this->restockLine( $locked, $line );
                }
            }

            $locked->total_refunded_amount   = (int) $locked->total_refunded_amount + (int) $amount->getAmount();
            $locked->total_refunded_currency = (string) $locked->currency;
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
                    'amount'            => (int) $amount->getAmount(),
                    'currency'          => (string) $locked->currency,
                    'reason'            => $reason,
                    'gateway'           => $gateway->key(),
                    'gateway_reference' => $result->gatewayReference,
                    'payment_status'    => $locked->payment_status,
                ],
            ] );

            return $locked->fresh( 'items' ) ?? $locked;
        } );

        $refund = $refund->fresh( 'items' ) ?? $refund;

        AfterCommit::action( 'ap.ecommerce.payment.refunded', $result, $refreshed );
        AfterCommit::action( 'ap.ecommerce.order.refunded', $refreshed, $refund );
        Event::dispatch( new PaymentRefunded( $refreshed, $result ) );
        Event::dispatch( new OrderRefunded( $refreshed, $refund ) );

        return $refund;
    }

    /**
     * Step 1: validates the request under the order lock and writes the
     * pending refund and its lines.
     *
     * @since 1.0.0
     *
     * @param  Order                             $order        Order.
     * @param  array<int, array<string, mixed>>  $lines        Refund lines.
     * @param  int|null                          $actorUserId  Admin.
     * @param  string|null                       $reason       Reason.
     *
     * @throws RefundNotAllowedException When the refund isn't allowed.
     *
     * @return array{0: Refund, 1: PaymentGateway, 2: Money, 3: array<int, array{order_item_id: int, quantity: int, amount: int, tax_amount: int, shipping_amount: int, restock: bool}>}
     */
    protected function openRefund( Order $order, array $lines, ?int $actorUserId, ?string $reason ): array
    {
        return DB::transaction( function () use ( $order, $lines, $actorUserId, $reason ): array {
            $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );
            $locked->load( 'items' );

            $this->guardOrderRefundable( $locked );

            $gateway    = $this->resolveGateway( $locked );
            $normalized = $this->normalizeLines( $locked, $lines );

            $refundAmount = array_sum( array_column( $normalized, 'amount' ) );

            $this->guardRefundBalance( $locked, $refundAmount );
            $this->guardPartialRefundCapability( $locked, $gateway, $refundAmount );
            $this->guardPerItemCaps( $locked, $normalized );

            $currency = (string) $locked->currency;
            $money    = new Money( (string) $refundAmount, CurrencyVO::of( $currency )->toMoneyPhp() );

            $filtered = applyFilters( 'ap.ecommerce.payment.refunding', $money, $locked, $reason );

            if ( null === $filtered ) {
                throw new RefundNotAllowedException( __( 'The refund for order :order was stopped by a payment.refunding filter.', [ 'order' => $locked->id ] ) );
            }

            // The lines are the refund's record, so a filter may veto the
            // refund but not change its total away from them.
            if ( ! $filtered instanceof Money || ! $filtered->equals( $money ) ) {
                throw new RefundNotAllowedException( __( 'A payment.refunding filter changed the refund for order :order to :returned :returned_currency; refunds must equal the sum of their lines (:requested :currency).', [
                    'order'             => $locked->id,
                    'returned'          => $filtered instanceof Money ? $filtered->getAmount() : '?',
                    'returned_currency' => $filtered instanceof Money ? $filtered->getCurrency()->getCode() : '?',
                    'requested'         => $money->getAmount(),
                    'currency'          => $currency,
                ] ) );
            }

            $refund = Refund::query()->create( [
                'order_id'          => $locked->id,
                'amount'            => $refundAmount,
                'currency'          => $currency,
                'reason'            => null !== $reason ? mb_substr( $reason, 0, 255 ) : null,
                'issued_by_user_id' => $actorUserId,
                'status'            => Refund::STATUS_PENDING,
            ] );

            foreach ( $normalized as $line ) {
                RefundItem::query()->create( [
                    'refund_id'       => $refund->id,
                    'order_item_id'   => $line['order_item_id'],
                    'quantity'        => $line['quantity'],
                    'amount'          => $line['amount'],
                    'tax_amount'      => $line['tax_amount'],
                    'shipping_amount' => $line['shipping_amount'],
                    'currency'        => $currency,
                    'restock'         => $line['restock'],
                ] );
            }

            return [ $refund, $gateway, $money, $normalized ];
        } );
    }

    /**
     * Settles a pending refund `failed` and records why.
     *
     * @since 1.0.0
     *
     * @param  Refund          $refund   Pending refund.
     * @param  PaymentGateway  $gateway  Gateway that refused it.
     * @param  string          $error    Why.
     *
     * @return void
     */
    protected function failRefund( Refund $refund, PaymentGateway $gateway, string $error ): void
    {
        DB::transaction( function () use ( $refund, $gateway, $error ): void {
            $refund->settle( Refund::STATUS_FAILED );

            OrderTimelineEntry::query()->create( [
                'order_id'      => $refund->order_id,
                'actor_user_id' => $refund->issued_by_user_id,
                'event_type'    => 'refund.failed',
                'payload'       => [
                    'refund_id' => $refund->id,
                    'amount'    => (int) $refund->amount,
                    'currency'  => (string) $refund->currency,
                    'gateway'   => $gateway->key(),
                    'error'     => $error,
                ],
            ] );
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
                'order_item_id'   => $orderItemId,
                'quantity'        => $quantity,
                'amount'          => $amount,
                'tax_amount'      => 0,
                'shipping_amount' => 0,
                'restock'         => (bool) ( $line['restock'] ?? false ),
            ];
        }

        return $this->apportionLines( $order, $out );
    }

    /**
     * Caps each line's amount at what is still refundable on its order line
     * (the line total less earlier refunds) and works out its tax and
     * shipping parts in proportion to the order line, never exceeding the
     * tax or shipping still unrefunded on it.
     *
     * @since 1.0.0
     *
     * @param  Order                             $order  Locked order with items.
     * @param  array<int, array<string, mixed>>  $lines  Normalized lines.
     *
     * @throws RefundNotAllowedException When a line asks for more than its order line has left.
     *
     * @return array<int, array{order_item_id: int, quantity: int, amount: int, tax_amount: int, shipping_amount: int, restock: bool}>
     */
    protected function apportionLines( Order $order, array $lines ): array
    {
        $items = $order->items->keyBy( 'id' );
        $prior = RefundItem::query()
            ->whereIn( 'order_item_id', array_unique( array_column( $lines, 'order_item_id' ) ) )
            ->whereHas( 'refund', static fn ( $query ) => $query->counting() )
            ->selectRaw( 'order_item_id, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(tax_amount), 0) AS tax, COALESCE(SUM(shipping_amount), 0) AS shipping' )
            ->groupBy( 'order_item_id' )
            ->get()
            ->keyBy( 'order_item_id' );

        $used = [];

        foreach ( $lines as $index => $line ) {
            $id   = $line['order_item_id'];
            $item = $items[ $id ];

            $used[ $id ] ??= [
                'amount'   => (int) ( $prior[ $id ]->amount ?? 0 ),
                'tax'      => (int) ( $prior[ $id ]->tax ?? 0 ),
                'shipping' => (int) ( $prior[ $id ]->shipping ?? 0 ),
            ];

            $lineTotal = max( 0, (int) $item->total_amount );
            $remaining = $lineTotal - $used[ $id ]['amount'];

            if ( $line['amount'] > $remaining ) {
                throw new RefundNotAllowedException( __( 'Refund amount :amount for order item :item exceeds the :remaining still refundable on that line.', [
                    'amount'    => $line['amount'],
                    'item'      => $id,
                    'remaining' => max( 0, $remaining ),
                ] ) );
            }

            $tax      = $this->portion( $line['amount'], (int) $item->tax_amount, $lineTotal, (int) $item->tax_amount - $used[ $id ]['tax'] );
            $shipping = $this->portion( $line['amount'], (int) $item->shipping_amount, $lineTotal, (int) $item->shipping_amount - $used[ $id ]['shipping'] );

            $lines[ $index ]['tax_amount']      = $tax;
            $lines[ $index ]['shipping_amount'] = $shipping;

            $used[ $id ]['amount'] += $line['amount'];
            $used[ $id ]['tax'] += $tax;
            $used[ $id ]['shipping'] += $shipping;
        }

        return array_values( $lines );
    }

    /**
     * `$amount`'s share of `$part` out of `$whole`, rounded half up and never
     * more than `$cap`.
     *
     * @since 1.0.0
     *
     * @param  int  $amount  Refunded amount.
     * @param  int  $part    The line's tax or shipping.
     * @param  int  $whole   The line's total.
     * @param  int  $cap     What of `$part` is still unrefunded.
     *
     * @return int
     */
    protected function portion( int $amount, int $part, int $whole, int $cap ): int
    {
        if ( $whole <= 0 || $part <= 0 || $cap <= 0 ) {
            return 0;
        }

        $share = (int) bcdiv( bcadd( bcmul( (string) $amount, (string) $part ), (string) intdiv( $whole, 2 ) ), (string) $whole, 0 );

        return min( $share, $cap );
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
        $outstanding = $this->outstanding( $order );

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

        $outstanding = $this->outstanding( $order );

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
            ->whereHas( 'refund', static fn ( $query ) => $query->counting() )
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
     * What can still be refunded on `$order`: its total less settled
     * refunds and refunds still in flight.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Locked order.
     *
     * @return int
     */
    protected function outstanding( Order $order ): int
    {
        $pending = (int) Refund::query()
            ->where( 'order_id', $order->id )
            ->where( 'status', Refund::STATUS_PENDING )
            ->sum( 'amount' );

        return (int) $order->total_amount - (int) $order->total_refunded_amount - $pending;
    }

    /**
     * Puts the refunded units back on the shelf: every tracked inventory row
     * the line drew on (a bundle's members, a variant's own row) gets
     * `quantity × units per item` back — the same rows payment capture
     * committed, so a restock exactly undoes the sale.
     *
     * Missing or untracked inventory rows are skipped: refunding a digital
     * or inventory-less product still records the ledger, it just has no
     * stock to restore. Stock writes go through {@see InventoryService::adjust()}
     * so `ap.ecommerce.inventory.*` hooks fire as for any stock change.
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

        if ( null === $item || null === $item->product || $line['quantity'] < 1 ) {
            return;
        }

        $variant = null === $item->product_variant_id ? null : ProductVariant::query()->find( $item->product_variant_id );

        foreach ( $this->stock->components( $item->product, $variant, (int) $line['quantity'] ) as $component ) {
            $inventory = $this->stock->itemFor( $component['stockable'] );

            if ( null === $inventory || ! $inventory->track_inventory ) {
                continue;
            }

            $this->inventory->adjust(
                $inventory,
                (int) $component['quantity'],
                sprintf( 'refund.restock:order#%d:item#%d', $order->id, $line['order_item_id'] ),
            );
        }
    }
}
