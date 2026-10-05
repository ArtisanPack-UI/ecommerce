<?php

/**
 * OrderFulfilled event.
 *
 * Every shippable line of an order has shipped (webhook `order.fulfilled`).
 * Dispatched after the surrounding transaction commits (audit I1).
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
class OrderFulfilled implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  Order  $order  The fulfilled order.
     */
    public function __construct(
        public readonly Order $order,
    ) {
    }
}
