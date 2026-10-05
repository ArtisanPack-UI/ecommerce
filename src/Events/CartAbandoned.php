<?php

/**
 * CartAbandoned event.
 *
 * Dispatched by `ecommerce:flag-abandoned-carts` once per cart, when a
 * shopper who started checkout and left an email hasn't touched the cart for
 * `cart.abandoned_after_minutes` (parent plan §7.3). Engine spec §7 event #3.
 * Abandoned-cart satellites start their email sequence from it.
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
class CartAbandoned implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  Cart  $cart  The abandoned cart.
     */
    public function __construct(
        public readonly Cart $cart,
    ) {
    }
}
