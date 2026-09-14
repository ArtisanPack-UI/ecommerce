<?php

/**
 * CartUpdated event.
 *
 * Dispatched by every mutating {@see \ArtisanPackUI\Ecommerce\Services\CartService}
 * method after a cart's item set or headline state changes. Engine spec §7
 * event #2.
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

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CartUpdated
{
    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart     The cart after the change.
     * @param  array<string, mixed>  $changes  Free-form diff describing what changed.
     */
    public function __construct(
        public readonly Cart $cart,
        public readonly array $changes = [],
    ) {
    }
}
