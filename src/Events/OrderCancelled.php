<?php

/**
 * OrderCancelled event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\OrderCancellationService::cancel()}
 * after the order has moved to `cancelled`, its inventory reservations have
 * been released, and any uncaptured payment has been voided. Engine spec §7
 * event #10.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Events;

use ArtisanPackUI\Ecommerce\Models\Order;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderCancelled
{
    /**
     * @since 1.0.0
     *
     * @param  Order   $order   The refreshed, cancelled order.
     * @param  string  $reason  Why it was cancelled.
     */
    public function __construct(
        public readonly Order $order,
        public readonly string $reason,
    ) {
    }
}
