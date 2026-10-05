<?php

/**
 * CartCreated event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\CartService::create()}
 * after a new cart row is persisted. Engine spec §7 event #1.
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
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CartCreated implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  Cart  $cart  The newly-created cart.
     */
    public function __construct(
        public readonly Cart $cart,
    ) {
    }
}
