<?php

/**
 * OrderStatusChanged event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\OrderStatusMachine::transition()}
 * after a `system_status` transition has been persisted and the
 * `order.status_changed` timeline entry has been written. Engine spec §7
 * event #7; plan §5.7.
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
class OrderStatusChanged
{
    /**
     * @since 1.0.0
     *
     * @param  Order   $order  The refreshed order after the transition.
     * @param  string  $from   The `system_status` before the transition.
     * @param  string  $to     The `system_status` after the transition.
     */
    public function __construct(
        public readonly Order $order,
        public readonly string $from,
        public readonly string $to,
    ) {
    }
}
