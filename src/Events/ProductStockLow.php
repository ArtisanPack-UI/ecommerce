<?php

/**
 * ProductStockLow event.
 *
 * An inventory item fell to or below its low-stock threshold (webhook `product.stock.low`).
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
class ProductStockLow implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  InventoryItem  $item    The inventory row.
     * @param  int            $onHand  Units on hand now.
     */
    public function __construct(
        public readonly InventoryItem $item,
        public readonly int $onHand,
    ) {
    }
}
