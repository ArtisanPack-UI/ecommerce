<?php

/**
 * OrderEditService.
 *
 * Applies post-placement changes to an {@see Order} atomically, records the
 * diff and the pre-edit snapshot on an append-only {@see OrderEdit} row,
 * writes a matching `order.edited` timeline entry, and dispatches the
 * {@see OrderEdited} event. Plan §7.6, engine spec §3.20.
 *
 * The service focuses on the domain contract: it does not talk to payment
 * gateways or refund APIs. Instead it returns an {@see OrderEditResult} whose
 * `paymentActionRequired` / `refundDelta` fields describe what has to happen
 * downstream; payment satellites listen on {@see OrderEdited} and act.
 *
 * When an edit changes the lines, the shipping amount, or the shipping
 * address (and doesn't set the tax itself), tax is recalculated through the
 * active tax provider and shipping re-split across the lines, as at
 * placement (audit D8). The totals then run through
 * `ap.ecommerce.order.recomputingTotals`. Added lines must be priced in the
 * order's currency.
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

use ArtisanPackUI\Ecommerce\Events\OrderEdited;
use ArtisanPackUI\Ecommerce\Exceptions\OrderNotEditableException;
use ArtisanPackUI\Ecommerce\Fulfillment\LineAllocator;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderEdit;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Models\RefundItem;
use ArtisanPackUI\Ecommerce\Models\ShipmentItem;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\OrderEditResult;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use RuntimeException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderEditService
{
    /**
     * Scalar order fields the service knows how to edit.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected const EDITABLE_FIELDS = [
        'email',
        'phone',
        'customer_note',
        'shipping_method_key',
        'shipping_address',
        'billing_address',
    ];

    /**
     * Scalar order fields that stay editable after fulfillment has started.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected const POST_FULFILLMENT_SAFE_FIELDS = [
        'shipping_address',
        'billing_address',
        'customer_note',
    ];

    /**
     * Hard upper bound on any single `items.add|change|remove` array, per
     * edit call. Prevents an admin request from holding the order's row
     * lock for a runaway number of writes.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const MAX_ITEMS_PER_DIRECTIVE = 200;

    /**
     * System statuses in which an order can't be edited (or rolled back):
     * its money and stock have already been settled.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected const LOCKED_STATUSES = [ 'cancelled', 'refunded', 'failed' ];

    /**
     * @since 1.0.0
     *
     * @param  TaxService     $taxes  Recalculates tax after line or shipping changes.
     * @param  LineAllocator  $lines  Splits shipping and shipping tax across the lines.
     */
    public function __construct(
        protected TaxService $taxes,
        protected LineAllocator $lines,
    ) {
    }

    /**
     * Applies an edit to `$order`.
     *
     * The `$edit` array may carry any subset of {@see self::EDITABLE_FIELDS},
     * plus:
     *
     * - `shipping_amount`, `tax_amount`, `discount_amount` (integer minor
     *   units) — overrides for the corresponding order totals.
     * - `items.add`    — list of new line specs, each carrying `product_id`,
     *                    `quantity`, `unit_price_amount`, `unit_price_currency`,
     *                    `product_snapshot` (plus optional `product_variant_id`,
     *                    `meta`).
     * - `items.remove` — list of `order_items.id` values to delete.
     * - `items.change` — map of `order_items.id` to a partial-update payload
     *                    (`quantity`, `unit_price_amount`, `tax_amount`,
     *                    `shipping_amount`, `discount_amount`).
     *
     * @since 1.0.0
     *
     * @param  Order                 $order         The order to edit. Callers should pass a freshly-loaded instance.
     * @param  array<string, mixed>  $edit          The edit payload.
     * @param  int|null              $actorUserId   Auth user id of the admin applying the edit; `null` for system.
     * @param  string|null           $reason        Free-text reason logged on the audit row.
     *
     * @throws OrderNotEditableException When the order state does not permit the requested changes.
     * @throws InvalidArgumentException  When the edit payload is malformed.
     *
     * @return OrderEditResult
     */
    public function apply(
        Order $order,
        array $edit,
        ?int $actorUserId = null,
        ?string $reason = null,
    ): OrderEditResult {
        return DB::transaction( function () use ( $order, $edit, $actorUserId, $reason ): OrderEditResult {
            [ $refreshed, $snapshot, $diff ] = $this->applyToLockedOrder( $order, $edit );

            return $this->finalize( $refreshed, $snapshot, $diff, $actorUserId, $reason );
        } );
    }

    /**
     * Works out what {@see self::apply()} would do to `$order`, without
     * keeping any of it.
     *
     * The edit runs exactly as `apply()` runs it — the
     * `ap.ecommerce.order.editing` and `ap.ecommerce.order.recomputingTotals`
     * filters included, so a tax provider recalculates as it would for real
     * — inside a transaction that is always rolled back. No audit row or
     * timeline entry is written, and no `ap.ecommerce.order.edited` action
     * or {@see OrderEdited} event fires. Ids of added lines in the returned
     * diff belonged to rows that were rolled back and do not exist.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order  The order to edit.
     * @param  array<string, mixed>  $edit   The edit payload, as for `apply()`.
     *
     * @throws OrderNotEditableException When the order state does not permit the requested changes.
     * @throws InvalidArgumentException  When the edit payload is malformed.
     *
     * @return array{diff: array<string, mixed>, paymentActionRequired: array{delta_amount:int,currency:string}|null, refundDelta: array{delta_amount:int,currency:string}|null}
     */
    public function preview( Order $order, array $edit ): array
    {
        $connection = ( new Order() )->getConnection();
        $connection->beginTransaction();

        try {
            [ $refreshed, $snapshot, $diff ] = $this->applyToLockedOrder( $order, $edit );
        } finally {
            $connection->rollBack();
        }

        [ $paymentActionRequired, $refundDelta ] = $this->totalDelta( $snapshot, $refreshed );

        return [
            'diff'                  => $diff,
            'paymentActionRequired' => $paymentActionRequired,
            'refundDelta'           => $refundDelta,
        ];
    }

    /**
     * Reverts `$order` to the state captured by `$edit->pre_edit_snapshot`.
     *
     * Rollback is itself an edit: it writes a new append-only `order_edits`
     * row whose diff describes the reverse change (so the audit trail stays
     * append-only). Rolling back beyond the most recent edit chains snapshots
     * in reverse order — call `rollback()` repeatedly on the target edits.
     *
     * @since 1.0.0
     *
     * @param  OrderEdit    $editToReverse
     * @param  int|null     $actorUserId
     * @param  string|null  $reason
     *
     * @throws OrderNotEditableException When the current order state rejects the reverse edit.
     *
     * @return OrderEditResult
     */
    public function rollback(
        OrderEdit $editToReverse,
        ?int $actorUserId = null,
        ?string $reason = null,
    ): OrderEditResult {
        return DB::transaction( function () use ( $editToReverse, $actorUserId, $reason ): OrderEditResult {
            $order  = Order::query()->lockForUpdate()->findOrFail( $editToReverse->order_id );
            $target = $editToReverse->pre_edit_snapshot;

            $order->load( 'items' );
            $snapshot = $this->snapshotOrder( $order );

            // A rollback is an edit: the same lifecycle rules apply to the
            // fields and lines it would put back.
            $this->guardEditability( $order, $this->rollbackKeys( $snapshot, (array) $target ) );

            $this->restoreFromSnapshot( $order, $target );

            $order->save();
            $refreshed = $order->fresh( 'items' ) ?? $order;

            $diff                        = $this->buildDiff( $snapshot, $this->snapshotOrder( $refreshed ) );
            $diff['rollback_of_edit_id'] = $editToReverse->id;

            $reason ??= sprintf( 'Rollback of order edit #%d', $editToReverse->id );

            return $this->finalize( $refreshed, $snapshot, $diff, $actorUserId, $reason );
        } );
    }

    /**
     * Locks the order, applies the filtered edit, recomputes totals, and
     * saves. Shared by {@see self::apply()} and {@see self::preview()}; the
     * caller owns the transaction.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order  The order to edit.
     * @param  array<string, mixed>  $edit   The edit payload.
     *
     * @throws OrderNotEditableException
     * @throws InvalidArgumentException
     *
     * @return array{0: Order, 1: array<string, mixed>, 2: array<string, mixed>} The refreshed order, the pre-edit snapshot, and the diff.
     */
    protected function applyToLockedOrder( Order $order, array $edit ): array
    {
        $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );
        $locked->load( 'items' );

        /** @var array<string, mixed> $filtered */
        $filtered = (array) applyFilters( 'ap.ecommerce.order.editing', $edit, $locked );

        $this->guardEditability( $locked, $filtered );

        $snapshot = $this->snapshotOrder( $locked );

        $this->applyScalarFields( $locked, $filtered );
        $this->applyItemChanges( $locked, $filtered );

        $this->applyTotalOverrides( $locked, $filtered );

        if ( $this->changesTaxBasis( $filtered ) ) {
            $this->recomputeTax( $locked );
        }

        $this->recomputeItemTotals( $locked );
        $this->recomputeOrderTotals( $locked );

        $locked->save();
        $refreshed = $locked->fresh( 'items' ) ?? $locked;

        return [ $refreshed, $snapshot, $this->buildDiff( $snapshot, $this->snapshotOrder( $refreshed ) ) ];
    }

    /**
     * The payment action or refund an edit's total change calls for.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $preEditSnapshot  Snapshot before the edit.
     * @param  Order                 $order            The order after it.
     *
     * @return array{0: array{delta_amount:int,currency:string}|null, 1: array{delta_amount:int,currency:string}|null}
     */
    protected function totalDelta( array $preEditSnapshot, Order $order ): array
    {
        $delta    = (int) $order->total_amount - (int) ( $preEditSnapshot['totals']['total_amount'] ?? 0 );
        $currency = (string) $order->currency;

        return match ( true ) {
            $delta > 0 => [ [ 'delta_amount' => $delta, 'currency' => $currency ], null ],
            $delta < 0 => [ null, [ 'delta_amount' => -$delta, 'currency' => $currency ] ],
            default    => [ null, null ],
        };
    }

    /**
     * Rejects edits the order's current lifecycle state does not permit.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order
     * @param  array<string, mixed>  $edit
     *
     * @throws OrderNotEditableException
     *
     * @return void
     */
    protected function guardEditability( Order $order, array $edit ): void
    {
        if ( in_array( (string) $order->system_status, self::LOCKED_STATUSES, true ) ) {
            throw new OrderNotEditableException( __( 'Order :order is :status and can no longer be edited.', [
                'order'  => $order->id,
                'status' => (string) $order->system_status,
            ] ) );
        }

        $fulfillment = (string) $order->fulfillment_status;

        if ( 'unfulfilled' === $fulfillment ) {
            return;
        }

        // Plan §7.6: "After any fulfillment has started: address details and
        // notes only." Anything outside POST_FULFILLMENT_SAFE_FIELDS — including
        // email, phone, shipping_method_key, item directives, and total
        // overrides — requires reversing the shipment first.
        foreach ( array_keys( $edit ) as $key ) {
            if ( in_array( $key, self::POST_FULFILLMENT_SAFE_FIELDS, true ) ) {
                continue;
            }

            throw new OrderNotEditableException( __( 'Order :order is in fulfillment_status ":status"; only shipping/billing address and notes are editable at this stage. Field ":field" is not.', [
                'order'  => $order->id,
                'status' => $fulfillment,
                'field'  => $key,
            ] ) );
        }
    }

    /**
     * Applies scalar-field edits to `$order`.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order
     * @param  array<string, mixed>  $edit
     *
     * @return void
     */
    protected function applyScalarFields( Order $order, array $edit ): void
    {
        foreach ( self::EDITABLE_FIELDS as $field ) {
            if ( array_key_exists( $field, $edit ) ) {
                $order->{$field} = $edit[ $field ];
            }
        }
    }

    /**
     * Applies line-item add/remove/change directives to `$order`.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order
     * @param  array<string, mixed>  $edit
     *
     * @throws InvalidArgumentException
     *
     * @return void
     */
    protected function applyItemChanges( Order $order, array $edit ): void
    {
        if ( ! isset( $edit['items'] ) || ! is_array( $edit['items'] ) ) {
            return;
        }

        $items = $edit['items'];

        $this->assertDirectiveSize( $items['add'] ?? [], 'items.add' );
        $this->assertDirectiveSize( $items['change'] ?? [], 'items.change' );
        $this->assertDirectiveSize( $items['remove'] ?? [], 'items.remove' );

        if ( ! empty( $items['remove'] ) ) {
            $ids = array_map( 'intval', (array) $items['remove'] );
            $this->guardRemovable( $order, $ids );
            OrderItem::query()
                ->where( 'order_id', $order->id )
                ->whereIn( 'id', $ids )
                ->delete();
        }

        if ( ! empty( $items['change'] ) ) {
            foreach ( (array) $items['change'] as $id => $changes ) {
                /** @var OrderItem|null $item */
                $item = OrderItem::query()
                    ->where( 'order_id', $order->id )
                    ->find( (int) $id );

                if ( null === $item ) {
                    throw new InvalidArgumentException( sprintf(
                        'Cannot change order item %d: it does not belong to order %d.',
                        (int) $id,
                        $order->id,
                    ) );
                }

                $changes = (array) $changes;
                $this->assertChangeBounds( (int) $id, $changes );

                if ( array_key_exists( 'quantity', $changes ) ) {
                    $this->guardQuantity( $order, $item, (int) $changes['quantity'] );
                }

                foreach ( [ 'quantity', 'unit_price_amount', 'tax_amount', 'shipping_amount', 'discount_amount' ] as $field ) {
                    if ( array_key_exists( $field, $changes ) ) {
                        $item->{$field} = (int) $changes[ $field ];
                    }
                }

                if ( isset( $changes['product_variant_id'] ) ) {
                    $item->product_variant_id = (int) $changes['product_variant_id'];
                }

                $item->save();
            }
        }

        if ( ! empty( $items['add'] ) ) {
            foreach ( (array) $items['add'] as $line ) {
                $this->validateNewLine( (array) $line );

                $currency = strtoupper( (string) $line['unit_price_currency'] );

                if ( $currency !== strtoupper( (string) $order->currency ) ) {
                    throw new InvalidArgumentException( sprintf(
                        'Order edit items.add line is priced in %s; the order is in %s.',
                        $currency,
                        $order->currency,
                    ) );
                }

                OrderItem::query()->create( [
                    'order_id'            => $order->id,
                    'product_id'          => (int) $line['product_id'],
                    'product_variant_id'  => isset( $line['product_variant_id'] ) ? (int) $line['product_variant_id'] : null,
                    'product_snapshot'    => (array) $line['product_snapshot'],
                    'quantity'            => (int) $line['quantity'],
                    'unit_price_amount'   => (int) $line['unit_price_amount'],
                    'unit_price_currency' => $currency,
                    'discount_amount'     => (int) ( $line['discount_amount'] ?? 0 ),
                    'discount_currency'   => $currency,
                    'tax_amount'          => (int) ( $line['tax_amount'] ?? 0 ),
                    'tax_currency'        => $currency,
                    'shipping_amount'     => (int) ( $line['shipping_amount'] ?? 0 ),
                    'shipping_currency'   => $currency,
                    'total_amount'        => 0,
                    'total_currency'      => $currency,
                    'fulfillment_status'  => (string) ( $line['fulfillment_status'] ?? 'unfulfilled' ),
                    'meta'                => (array) ( $line['meta'] ?? [] ),
                ] );
            }
        }

        $order->load( 'items' );
    }

    /**
     * Copies any direct total overrides from the edit payload onto the order.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order
     * @param  array<string, mixed>  $edit
     *
     * @return void
     */
    protected function applyTotalOverrides( Order $order, array $edit ): void
    {
        foreach ( [ 'shipping_amount', 'tax_amount', 'discount_amount' ] as $field ) {
            if ( ! array_key_exists( $field, $edit ) ) {
                continue;
            }

            $value = (int) $edit[ $field ];

            if ( $value < 0 ) {
                throw new InvalidArgumentException( sprintf(
                    'Order edit "%s" override must be a non-negative integer; got %d.',
                    $field,
                    $value,
                ) );
            }

            $order->{$field} = $value;
        }
    }

    /**
     * Whether an edit changes what tax is charged on: the lines, the
     * shipping amount, or the shipping address — and doesn't set the tax
     * itself (an order-level `tax_amount`, or a line's `tax_amount`).
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $edit  Filtered edit.
     *
     * @return bool
     */
    protected function changesTaxBasis( array $edit ): bool
    {
        if ( array_key_exists( 'tax_amount', $edit ) ) {
            return false;
        }

        $items = (array) ( $edit['items'] ?? [] );

        foreach ( (array) ( $items['change'] ?? [] ) as $changes ) {
            if ( is_array( $changes ) && array_key_exists( 'tax_amount', $changes ) ) {
                return false;
            }
        }

        foreach ( (array) ( $items['add'] ?? [] ) as $line ) {
            if ( is_array( $line ) && array_key_exists( 'tax_amount', $line ) ) {
                return false;
            }
        }

        return [] !== array_filter( [ $items['add'] ?? null, $items['remove'] ?? null, $items['change'] ?? null ] )
            || array_key_exists( 'shipping_amount', $edit )
            || array_key_exists( 'shipping_address', $edit );
    }

    /**
     * Recalculates the order's tax through the active tax provider for its
     * edited lines and address (audit D8), then splits shipping and
     * shipping tax across the lines. The provider sees a transient cart
     * built from the order (it isn't saved). An order with no address keeps
     * its tax.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Locked order with its items.
     *
     * @return void
     */
    protected function recomputeTax( Order $order ): void
    {
        $address = $order->shipping_address ?? $order->billing_address;

        if ( ! is_array( $address ) || 2 !== strlen( (string) ( $address['country_code'] ?? '' ) ) ) {
            return;
        }

        $order->load( 'items.product' );

        $currency = strtoupper( (string) $order->currency );
        $cart     = new Cart( [ 'currency' => $currency ] );
        $cart->forceFill( [
            'currency'        => $currency,
            'discount_amount' => (int) $order->discount_amount,
            'shipping_amount' => (int) $order->shipping_amount,
        ] );

        $lines = $order->items->map( static function ( OrderItem $item ) use ( $currency ): CartItem {
            $line = new CartItem();
            $line->forceFill( [
                'id'                  => $item->id,
                'product_id'          => $item->product_id,
                'product_variant_id'  => $item->product_variant_id,
                'quantity'            => (int) $item->quantity,
                'unit_price_amount'   => (int) $item->unit_price_amount,
                'unit_price_currency' => $currency,
                'line_total_amount'   => (int) $item->unit_price_amount * (int) $item->quantity,
                'discount_amount'     => (int) $item->discount_amount,
            ] );
            $line->setRelation( 'product', $item->product );

            return $line;
        } );

        $cart->setRelation( 'items', new EloquentCollection( $lines->all() ) );

        $result = $this->taxes->calculate(
            $cart,
            Address::fromArray( $address ),
            $order->items->mapWithKeys( static fn ( OrderItem $item ): array => [ (int) $item->id => (int) $item->discount_amount ] )->all(),
        );

        $order->tax_amount = (int) $result->total->getAmount();

        $meta                  = (array) ( $order->meta ?? [] );
        $meta['tax_breakdown'] = array_values( array_map( static fn ( array $row ): array => [
            'label'        => $row['label'],
            'rate_ubps'    => $row['rate_ubps'],
            'amount'       => (int) $row['amount']->getAmount(),
            'is_compound'  => $row['is_compound'],
            'country_code' => strtoupper( (string) $address['country_code'] ),
            'region_code'  => $address['region_code'] ?? null,
        ], $result->breakdown ) );
        $order->meta = $meta;

        $this->lines->allocate(
            $order,
            $order->items,
            array_map( static fn ( $money ): int => (int) $money->getAmount(), $result->perLine ),
            $this->pricesIncludeTax( $order ),
        );

        $order->load( 'items' );
    }

    /**
     * Whether the order's prices include tax (recorded at placement; the
     * store setting for orders placed before it was recorded).
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return bool
     */
    protected function pricesIncludeTax( Order $order ): bool
    {
        $recorded = ( (array) ( $order->meta ?? [] ) )['prices_include_tax'] ?? null;

        return null === $recorded ? (bool) config( 'artisanpack.ecommerce.tax.prices_include_tax', false ) : (bool) $recorded;
    }

    /**
     * Rewrites each line's `total_amount` as `unit * qty - discount +
     * shipping + tax` (without the tax when prices include it).
     *
     * @since 1.0.0
     *
     * @param  Order  $order
     *
     * @return void
     */
    protected function recomputeItemTotals( Order $order ): void
    {
        $inclusive = $this->pricesIncludeTax( $order );

        foreach ( $order->items as $item ) {
            $lineSubtotal       = $item->unit_price_amount * $item->quantity;
            $item->total_amount = $lineSubtotal
                + ( $inclusive ? 0 : (int) $item->tax_amount )
                + (int) $item->shipping_amount
                - (int) $item->discount_amount;
            $item->save();
        }
    }

    /**
     * Rewrites the order-level `subtotal_amount` and `total_amount`.
     *
     * Line subtotals sum into `subtotal_amount`. `total_amount` is then
     * `subtotal + shipping + tax - discount`. Between the sum and the final
     * total, the `ap.ecommerce.order.recomputingTotals` filter runs so a
     * `TaxProvider` / `ShippingRateProvider` can override the interim values.
     *
     * @since 1.0.0
     *
     * @param  Order  $order
     *
     * @return void
     */
    protected function recomputeOrderTotals( Order $order ): void
    {
        $subtotal = 0;
        foreach ( $order->items as $item ) {
            $subtotal += $item->unit_price_amount * $item->quantity;
        }

        $order->subtotal_amount = $subtotal;

        /** @var mixed $mutated */
        $mutated = applyFilters( 'ap.ecommerce.order.recomputingTotals', $order );

        // The filter contract is `Order → Order` and it is expected to mutate in
        // place. But a listener may legitimately return a fresh Order instance
        // whose PK matches (e.g. `$order->replicate()` after tweaking totals).
        // The local variable `$order` here is a rebind that would not reach the
        // caller — so instead of swapping references, copy the totals fields
        // back onto the original instance we were given.
        if ( $mutated instanceof Order && $mutated !== $order && $mutated->is( $order ) ) {
            foreach ( [ 'subtotal_amount', 'discount_amount', 'tax_amount', 'shipping_amount' ] as $field ) {
                $order->{$field} = (int) $mutated->{$field};
            }
        }

        $order->total_amount = (int) $order->subtotal_amount
            + (int) $order->shipping_amount
            + ( $this->pricesIncludeTax( $order ) ? 0 : (int) $order->tax_amount )
            - (int) $order->discount_amount;
    }

    /**
     * Captures the fields, items, and totals that make up an order's edit
     * surface so a later {@see self::buildDiff()} pass can compare against it.
     *
     * @since 1.0.0
     *
     * @param  Order  $order
     *
     * @return array<string, mixed>
     */
    protected function snapshotOrder( Order $order ): array
    {
        $fields = [];
        foreach ( self::EDITABLE_FIELDS as $field ) {
            $fields[ $field ] = $order->{$field};
        }

        $items = [];
        foreach ( $order->items as $item ) {
            $items[ (int) $item->id ] = [
                'id'                  => (int) $item->id,
                'product_id'          => $item->product_id,
                'product_variant_id'  => $item->product_variant_id,
                'product_snapshot'    => $item->product_snapshot,
                'quantity'            => (int) $item->quantity,
                'unit_price_amount'   => (int) $item->unit_price_amount,
                'unit_price_currency' => $item->unit_price_currency,
                'tax_amount'          => (int) $item->tax_amount,
                'shipping_amount'     => (int) $item->shipping_amount,
                'discount_amount'     => (int) $item->discount_amount,
                'total_amount'        => (int) $item->total_amount,
                'total_currency'      => $item->total_currency,
                'fulfillment_status'  => $item->fulfillment_status,
                'meta'                => $item->meta,
            ];
        }

        return [
            'fields' => $fields,
            'items'  => $items,
            'totals' => [
                'subtotal_amount' => (int) $order->subtotal_amount,
                'discount_amount' => (int) $order->discount_amount,
                'tax_amount'      => (int) $order->tax_amount,
                'shipping_amount' => (int) $order->shipping_amount,
                'total_amount'    => (int) $order->total_amount,
                'currency'        => $order->currency,
            ],
        ];
    }

    /**
     * Restores an order + its line items to a shape produced by
     * {@see self::snapshotOrder()}.
     *
     * Uses `withoutEvents()` on {@see OrderItem} so items removed by the
     * current edit can be recreated at their original ids without tripping
     * the `product_snapshot` immutability guard — snapshots are the record
     * of history, and rollback re-establishes that history verbatim.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order
     * @param  array<string, mixed>  $snapshot
     *
     * @return void
     */
    protected function restoreFromSnapshot( Order $order, array $snapshot ): void
    {
        $fields = (array) ( $snapshot['fields'] ?? [] );
        foreach ( self::EDITABLE_FIELDS as $field ) {
            if ( array_key_exists( $field, $fields ) ) {
                $order->{$field} = $fields[ $field ];
            }
        }

        $totals = (array) ( $snapshot['totals'] ?? [] );
        foreach ( [ 'subtotal_amount', 'discount_amount', 'tax_amount', 'shipping_amount', 'total_amount' ] as $field ) {
            if ( array_key_exists( $field, $totals ) ) {
                $order->{$field} = (int) $totals[ $field ];
            }
        }

        $targetItems = (array) ( $snapshot['items'] ?? [] );
        $targetIds   = array_map( 'intval', array_keys( $targetItems ) );

        $this->guardRemovable( $order, $order->items->pluck( 'id' )->map( 'intval' )->diff( $targetIds )->values()->all() );

        foreach ( $order->items as $current ) {
            if ( isset( $targetItems[ $current->id ]['quantity'] ) ) {
                $this->guardQuantity( $order, $current, (int) $targetItems[ $current->id ]['quantity'] );
            }
        }

        OrderItem::query()
            ->where( 'order_id', $order->id )
            ->when(
                ! empty( $targetIds ),
                fn ( $q ) => $q->whereNotIn( 'id', $targetIds ),
            )
            ->delete();

        $existing = OrderItem::query()
            ->where( 'order_id', $order->id )
            ->get()
            ->keyBy( 'id' );

        foreach ( $targetItems as $id => $data ) {
            $id   = (int) $id;
            $data = (array) $data;

            /** @var OrderItem|null $item */
            $item = $existing->get( $id );

            if ( null === $item ) {
                if ( null !== OrderItem::query()->whereKey( $id )->first() ) {
                    throw new RuntimeException( sprintf(
                        'Cannot restore order item %d during rollback: an item with that id already exists on another order.',
                        $id,
                    ) );
                }

                OrderItem::withoutEvents( function () use ( $order, $id, $data ): void {
                    OrderItem::query()->insert( [
                        'id'                  => $id,
                        'order_id'            => $order->id,
                        'product_id'          => isset( $data['product_id'] ) ? (int) $data['product_id'] : null,
                        'product_variant_id'  => isset( $data['product_variant_id'] ) ? (int) $data['product_variant_id'] : null,
                        'product_snapshot'    => json_encode( $data['product_snapshot'] ?? [] ),
                        'quantity'            => (int) ( $data['quantity'] ?? 0 ),
                        'unit_price_amount'   => (int) ( $data['unit_price_amount'] ?? 0 ),
                        'unit_price_currency' => (string) ( $data['unit_price_currency'] ?? $order->currency ),
                        'discount_amount'     => (int) ( $data['discount_amount'] ?? 0 ),
                        'discount_currency'   => (string) ( $data['unit_price_currency'] ?? $order->currency ),
                        'tax_amount'          => (int) ( $data['tax_amount'] ?? 0 ),
                        'tax_currency'        => (string) ( $data['unit_price_currency'] ?? $order->currency ),
                        'shipping_amount'     => (int) ( $data['shipping_amount'] ?? 0 ),
                        'shipping_currency'   => (string) ( $data['unit_price_currency'] ?? $order->currency ),
                        'total_amount'        => (int) ( $data['total_amount'] ?? 0 ),
                        'total_currency'      => (string) ( $data['total_currency'] ?? $order->currency ),
                        'fulfillment_status'  => (string) ( $data['fulfillment_status'] ?? 'unfulfilled' ),
                        'meta'                => json_encode( (array) ( $data['meta'] ?? [] ) ),
                    ] );
                } );

                continue;
            }

            $item->quantity            = (int) ( $data['quantity'] ?? $item->quantity );
            $item->unit_price_amount   = (int) ( $data['unit_price_amount'] ?? $item->unit_price_amount );
            $item->tax_amount          = (int) ( $data['tax_amount'] ?? $item->tax_amount );
            $item->shipping_amount     = (int) ( $data['shipping_amount'] ?? $item->shipping_amount );
            $item->discount_amount     = (int) ( $data['discount_amount'] ?? $item->discount_amount );
            $item->total_amount        = (int) ( $data['total_amount'] ?? $item->total_amount );
            $item->fulfillment_status  = (string) ( $data['fulfillment_status'] ?? $item->fulfillment_status );
            $item->product_variant_id  = $data['product_variant_id'] ?? $item->product_variant_id;
            $item->save();
        }

        $order->load( 'items' );
    }

    /**
     * Builds the `{fields, items, totals}` diff shape persisted on
     * `order_edits.diff`. Engine spec §3.20.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     *
     * @return array<string, mixed>
     */
    protected function buildDiff( array $before, array $after ): array
    {
        $fieldDiff = [];
        foreach ( self::EDITABLE_FIELDS as $field ) {
            $b = $before['fields'][ $field ] ?? null;
            $a = $after['fields'][ $field ] ?? null;
            if ( $b !== $a ) {
                $fieldDiff[ $field ] = [ 'before' => $b, 'after' => $a ];
            }
        }

        $beforeItems = (array) ( $before['items'] ?? [] );
        $afterItems  = (array) ( $after['items'] ?? [] );

        $removed = [];
        foreach ( $beforeItems as $id => $item ) {
            if ( ! array_key_exists( $id, $afterItems ) ) {
                $removed[] = $item;
            }
        }

        $added = [];
        foreach ( $afterItems as $id => $item ) {
            if ( ! array_key_exists( $id, $beforeItems ) ) {
                $added[] = $item;
            }
        }

        $changed = [];
        foreach ( $afterItems as $id => $item ) {
            if ( ! array_key_exists( $id, $beforeItems ) ) {
                continue;
            }
            $prev        = $beforeItems[ $id ];
            $lineChanges = [];
            foreach ( $item as $key => $value ) {
                // Skip columns that are immutable after placement — comparing
                // them can produce spurious "changed" entries when a rollback
                // re-inserts an item and the database round-trips its JSON in
                // a different key order than the original snapshot recorded.
                if ( in_array( $key, [ 'id', 'product_id', 'product_snapshot', 'unit_price_currency', 'total_currency' ], true ) ) {
                    continue;
                }
                if ( ( $prev[ $key ] ?? null ) !== $value ) {
                    $lineChanges[ $key ] = [ 'before' => $prev[ $key ] ?? null, 'after' => $value ];
                }
            }
            if ( ! empty( $lineChanges ) ) {
                $changed[ $id ] = $lineChanges;
            }
        }

        return [
            'fields' => $fieldDiff,
            'items'  => [
                'added'   => $added,
                'removed' => $removed,
                'changed' => $changed,
            ],
            'totals' => [
                'before' => $before['totals'] ?? [],
                'after'  => $after['totals'] ?? [],
            ],
        ];
    }

    /**
     * Persists the audit row and timeline entry, dispatches the event, and
     * assembles the {@see OrderEditResult}.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order
     * @param  array<string, mixed>  $preEditSnapshot
     * @param  array<string, mixed>  $diff
     * @param  int|null              $actorUserId
     * @param  string|null           $reason
     *
     * @return OrderEditResult
     */
    protected function finalize(
        Order $order,
        array $preEditSnapshot,
        array $diff,
        ?int $actorUserId,
        ?string $reason,
    ): OrderEditResult {
        // `order_edits.reason` is VARCHAR(255) in the schema (spec §3.20).
        // Match that cap here so the timeline copy cannot drift longer than
        // what the audit row itself can hold.
        if ( null !== $reason ) {
            $reason = mb_substr( $reason, 0, 255 );
        }

        $editRow = OrderEdit::query()->create( [
            'order_id'          => $order->id,
            'actor_user_id'     => $actorUserId,
            'reason'            => $reason,
            'diff'              => $diff,
            'pre_edit_snapshot' => $preEditSnapshot,
        ] );

        OrderTimelineEntry::query()->create( [
            'order_id'      => $order->id,
            'actor_user_id' => $actorUserId,
            'event_type'    => 'order.edited',
            'payload'       => [
                'order_edit_id' => $editRow->id,
                'reason'        => $reason,
                'totals'        => $diff['totals'] ?? [],
            ],
        ] );

        [ $paymentActionRequired, $refundDelta ] = $this->totalDelta( $preEditSnapshot, $order );

        AfterCommit::action( 'ap.ecommerce.order.edited', $order, $diff, $editRow );
        Event::dispatch( new OrderEdited( $order, $diff, $editRow ) );

        return new OrderEditResult(
            $order,
            $editRow,
            $diff,
            $paymentActionRequired,
            $refundDelta,
        );
    }

    /**
     * Refuses to remove lines that have history: refunds, shipments,
     * license keys, or download entitlements reference them, and deleting
     * the line would delete (or orphan) that record of what happened.
     *
     * @since 1.0.0
     *
     * @param  Order           $order  The locked order.
     * @param  array<int, int> $ids    Ids of the lines to remove.
     *
     * @throws OrderNotEditableException When a line has history.
     *
     * @return void
     */
    protected function guardRemovable( Order $order, array $ids ): void
    {
        if ( [] === $ids ) {
            return;
        }

        $history = [
            'refunds'       => RefundItem::query()->whereIn( 'order_item_id', $ids )->whereHas( 'refund', static fn ( $query ) => $query->counting() )->pluck( 'order_item_id' ),
            'shipments'     => ShipmentItem::query()->whereIn( 'order_item_id', $ids )->pluck( 'order_item_id' ),
            'license keys'  => LicenseKey::query()->whereIn( 'order_item_id', $ids )->pluck( 'order_item_id' ),
            'downloads'     => DigitalDownload::query()->whereIn( 'order_item_id', $ids )->pluck( 'order_item_id' ),
        ];

        foreach ( $history as $kind => $itemIds ) {
            if ( $itemIds->isNotEmpty() ) {
                throw new OrderNotEditableException( __( 'Line :item on order :order has :history and can\'t be removed.', [
                    'item'    => (int) $itemIds->first(),
                    'order'   => $order->id,
                    'history' => match ( $kind ) {
                        'refunds'      => __( 'refunds' ),
                        'shipments'    => __( 'shipments' ),
                        'license keys' => __( 'license keys' ),
                        default        => __( 'download entitlements' ),
                    },
                ] ) );
            }
        }
    }

    /**
     * Refuses to set a line's quantity below what has already been refunded
     * or shipped from it.
     *
     * @since 1.0.0
     *
     * @param  Order      $order     The locked order.
     * @param  OrderItem  $item      The line.
     * @param  int        $quantity  The new quantity.
     *
     * @throws OrderNotEditableException When the quantity is too low.
     *
     * @return void
     */
    protected function guardQuantity( Order $order, OrderItem $item, int $quantity ): void
    {
        $refunded = (int) RefundItem::query()->where( 'order_item_id', $item->id )->whereHas( 'refund', static fn ( $query ) => $query->counting() )->sum( 'quantity' );
        $shipped  = (int) ShipmentItem::query()->where( 'order_item_id', $item->id )->sum( 'quantity' );
        $floor    = max( $refunded, $shipped );

        if ( $quantity < $floor ) {
            throw new OrderNotEditableException( __( 'Line :item on order :order can\'t go below :floor: that many units were already refunded or shipped.', [
                'item'  => $item->id,
                'order' => $order->id,
                'floor' => $floor,
            ] ) );
        }
    }

    /**
     * The edit keys a rollback from `$current` back to `$target` amounts to,
     * for {@see self::guardEditability()}.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $current  Snapshot of the order now.
     * @param  array<string, mixed>  $target   Snapshot the rollback restores.
     *
     * @return array<string, true>
     */
    protected function rollbackKeys( array $current, array $target ): array
    {
        $diff = $this->buildDiff( $current, $target );
        $keys = array_fill_keys( array_keys( $diff['fields'] ), true );

        if ( [] !== $diff['items']['added'] || [] !== $diff['items']['removed'] || [] !== $diff['items']['changed'] ) {
            $keys['items'] = true;
        }

        foreach ( [ 'shipping_amount', 'tax_amount', 'discount_amount' ] as $field ) {
            if ( ( $diff['totals']['before'][ $field ] ?? null ) !== ( $diff['totals']['after'][ $field ] ?? null ) ) {
                $keys[ $field ] = true;
            }
        }

        return $keys;
    }

    /**
     * Asserts that an `items.add` entry carries the columns the order-items
     * table requires.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $line
     *
     * @throws InvalidArgumentException
     *
     * @return void
     */
    protected function validateNewLine( array $line ): void
    {
        foreach ( [ 'product_id', 'quantity', 'unit_price_amount', 'unit_price_currency', 'product_snapshot' ] as $key ) {
            if ( ! array_key_exists( $key, $line ) ) {
                throw new InvalidArgumentException( sprintf( 'Order edit items.add entry missing required key "%s".', $key ) );
            }
        }

        if ( (int) $line['quantity'] <= 0 ) {
            throw new InvalidArgumentException( 'Order edit items.add quantity must be a positive integer.' );
        }

        foreach ( [ 'unit_price_amount', 'tax_amount', 'shipping_amount', 'discount_amount' ] as $moneyField ) {
            if ( array_key_exists( $moneyField, $line ) && (int) $line[ $moneyField ] < 0 ) {
                throw new InvalidArgumentException( sprintf(
                    'Order edit items.add "%s" must be a non-negative integer.',
                    $moneyField,
                ) );
            }
        }
    }

    /**
     * Guards an `items.change` payload against negative or zero quantities
     * and negative money fields. Callers can still legitimately set a money
     * field to zero (e.g. clear a per-line discount).
     *
     * @since 1.0.0
     *
     * @param  int                   $itemId
     * @param  array<string, mixed>  $changes
     *
     * @throws InvalidArgumentException
     *
     * @return void
     */
    protected function assertChangeBounds( int $itemId, array $changes ): void
    {
        if ( array_key_exists( 'quantity', $changes ) && (int) $changes['quantity'] <= 0 ) {
            throw new InvalidArgumentException( sprintf(
                'Order edit items.change[%d].quantity must be a positive integer; use items.remove to drop a line.',
                $itemId,
            ) );
        }

        foreach ( [ 'unit_price_amount', 'tax_amount', 'shipping_amount', 'discount_amount' ] as $moneyField ) {
            if ( array_key_exists( $moneyField, $changes ) && (int) $changes[ $moneyField ] < 0 ) {
                throw new InvalidArgumentException( sprintf(
                    'Order edit items.change[%d].%s must be a non-negative integer.',
                    $itemId,
                    $moneyField,
                ) );
            }
        }
    }

    /**
     * Enforces {@see self::MAX_ITEMS_PER_DIRECTIVE} on any array-valued items
     * directive.
     *
     * @since 1.0.0
     *
     * @param  mixed   $directive
     * @param  string  $label
     *
     * @throws InvalidArgumentException
     *
     * @return void
     */
    protected function assertDirectiveSize( mixed $directive, string $label ): void
    {
        if ( ! is_array( $directive ) ) {
            return;
        }

        if ( count( $directive ) > self::MAX_ITEMS_PER_DIRECTIVE ) {
            throw new InvalidArgumentException( sprintf(
                'Order edit "%s" carries %d entries; the per-call limit is %d. Split the edit into smaller batches.',
                $label,
                count( $directive ),
                self::MAX_ITEMS_PER_DIRECTIVE,
            ) );
        }
    }
}
