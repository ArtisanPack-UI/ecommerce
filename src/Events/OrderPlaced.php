<?php

/**
 * OrderPlaced event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\OrderPlacementService::place()}
 * once a cart has become an order and the placement has committed — before
 * payment is captured, so the order is still `pending`. Engine spec §7
 * event #6.
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
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderPlaced implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  Order  $order  The placed order.
     */
    public function __construct(
        public readonly Order $order,
    ) {
    }
}
