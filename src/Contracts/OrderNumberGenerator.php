<?php

/**
 * OrderNumberGenerator contract.
 *
 * Produces the human-visible `order_number` written to
 * {@see \ArtisanPackUI\Ecommerce\Models\Order::$order_number} during order
 * placement. Engine spec §4.9.
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

/**
 * OrderNumberGenerator contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface OrderNumberGenerator
{
    /**
     * Generates an order number for the given order.
     *
     * Implementations SHOULD avoid values already persisted on the
     * `orders` table so the common case does not round-trip through
     * placement retry. `generate()` does NOT need to atomically reserve
     * the returned value — concurrent collisions are contained by the
     * unique index on `orders.order_number` plus placement's one-shot
     * retry (returning a duplicate a second time is fatal).
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order being placed (may be used for prefixing/context).
     *
     * @return string
     */
    public function generate( Order $order ): string;
}
