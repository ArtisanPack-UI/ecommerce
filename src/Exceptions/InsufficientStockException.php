<?php

/**
 * InsufficientStockException.
 *
 * Thrown when a reservation cannot be created because the requested quantity
 * exceeds the effective available (`quantity_on_hand - quantity_reserved`)
 * and backorders are disabled.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Exceptions;

use ArtisanPackUI\Ecommerce\Models\InventoryItem;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class InsufficientStockException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  InventoryItem  $item       The inventory row the reservation targeted.
     * @param  int            $requested  Requested reservation quantity.
     * @param  int            $available  Effective available quantity at the moment of failure.
     */
    public function __construct(
        public readonly InventoryItem $item,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(
            sprintf(
                'Insufficient stock on inventory item #%d: requested %d, %d available.',
                $item->id ?? 0,
                $requested,
                $available,
            ),
        );
    }
}
