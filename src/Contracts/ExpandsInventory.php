<?php

/**
 * ExpandsInventory contract.
 *
 * Optional companion to {@see ProductType} for types whose stock lives on
 * other products — a bundle's members, a kit's parts. Checkout reserves,
 * and payment commits, the components instead of the product itself;
 * stock checks read the components too.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface ExpandsInventory
{
    /**
     * The stock `$quantity` of `$product` (or `$variant`) consumes.
     *
     * @since 1.0.0
     *
     * @param  Product              $product   Product being sold.
     * @param  ProductVariant|null  $variant   Variant being sold, if any.
     * @param  int                  $quantity  Units sold.
     *
     * @return array<int, array{stockable: Product|ProductVariant, quantity: int}>
     */
    public function inventoryComponents( Product $product, ?ProductVariant $variant, int $quantity ): array;
}
