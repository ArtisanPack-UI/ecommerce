<?php

/**
 * PromotionApplied event.
 *
 * Dispatched at order placement for each promotion the order was priced
 * with, once its usage is recorded and the placement has committed — not on
 * every cart evaluation, so listeners see only promotions that were used.
 * Engine spec §7 event #30.
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
use ArtisanPackUI\Ecommerce\Models\Promotion;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PromotionApplied implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  Promotion  $promotion  The applied promotion.
     * @param  Cart       $cart       The cart it was applied to (now converted).
     * @param  Money      $amount     Discount it gave.
     */
    public function __construct(
        public readonly Promotion $promotion,
        public readonly Cart $cart,
        public readonly Money $amount,
    ) {
    }
}
