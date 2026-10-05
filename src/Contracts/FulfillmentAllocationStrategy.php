<?php

/**
 * FulfillmentAllocationStrategy contract.
 *
 * Distributes an order's shipping and tax totals across its line items so
 * that partial shipments and per-line refunds have a canonical, deterministic
 * per-item value to work against. Engine spec §4.6 (parent plan §6.1, §16.7).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use Money\Money;

/**
 * FulfillmentAllocationStrategy contract.
 *
 * Implementations answer the question "given an `Order` with a single
 * shipping and tax total, how much of each belongs to each `OrderItem`?"
 * so that partial shipments and per-line refunds can reason about
 * shipping/tax at the line level.
 *
 * The result is a map keyed by `OrderItem::id` where every value is a
 * `{ shipping: Money, tax: Money }` pair. The sum of the per-item shipping
 * amounts MUST equal `Order::shipping_amount`, and the sum of the per-item
 * tax amounts MUST equal `Order::tax_amount` — every strategy is
 * responsible for absorbing any sub-cent residual so no rounding money
 * escapes into the void.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface FulfillmentAllocationStrategy
{
    /**
     * Stable registry key (e.g. `proportional-by-line-total`).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string;

    /**
     * Allocates the order's shipping and tax totals across its line items.
     *
     * Implementations MUST return a `{ shipping: Money, tax: Money }` pair
     * for every item passed in, keyed by `OrderItem::id`. The summed
     * per-item shipping equals `Order::shipping_amount`, and the summed
     * per-item tax equals `Order::tax_amount`.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order  Order whose totals are being split.
     * @param  iterable<OrderItem>   $items  Items to allocate across.
     *
     * @return array<int, array{shipping: Money, tax: Money}>
     */
    public function allocate( Order $order, iterable $items ): array;
}
