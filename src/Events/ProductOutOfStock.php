<?php

/**
 * ProductOutOfStock event.
 *
 * An inventory item ran out (webhook `product.out.of.stock`).
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

use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductOutOfStock implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  InventoryItem  $item  The inventory row.
     */
    public function __construct(
        public readonly InventoryItem $item,
    ) {
    }
}
