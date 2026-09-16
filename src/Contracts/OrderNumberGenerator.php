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
     * Generates a unique order number.
     *
     * Called inside the order-placement transaction; implementations MUST
     * be collision-safe under concurrent placement. Returning a duplicate
     * is fatal (placement retries once, then errors).
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order being placed (may be used for prefixing/context).
     *
     * @return string
     */
    public function generate( Order $order ): string;
}
