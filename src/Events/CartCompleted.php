<?php

/**
 * CartCompleted event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\OrderPlacementService::place()}
 * (through checkout finalize) once the cart has become `$order`. Engine
 * spec §7 event #4.
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

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CartCompleted implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  Cart   $cart   The converted cart.
     * @param  Order  $order  The order it became.
     */
    public function __construct(
        public readonly Cart $cart,
        public readonly Order $order,
    ) {
    }
}
