<?php

/**
 * CartMerged event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\CartMergeService::merge()}
 * after a guest cart is successfully merged into a destination cart. Engine
 * spec §7 event #5.
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
class CartMerged
{
    /**
     * @since 1.0.0
     *
     * @param  Cart  $result             The surviving (merged) cart.
     * @param  int   $guestItemsMerged   Number of guest-cart lines carried into the result.
     */
    public function __construct(
        public readonly Cart $result,
        public readonly int $guestItemsMerged,
    ) {
    }
}
