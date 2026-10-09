<?php

/**
 * StockLevels.
 *
 * Read-only stock answers shared by the cart, checkout, and storefront
 * reads (engine issues #169, #172). Unlike
 * {@see \ArtisanPackUI\Ecommerce\Services\ProductService::inventoryItemFor()}
 * it never creates inventory rows.
 *
 * - {@see self::components()} is the stock a line consumes: the product or
 *   variant itself, or its parts when the product type implements
 *   {@see ExpandsInventory} (bundles). Types that aren't inventory-tracked
 *   (digital, grouped) consume nothing.
 * - {@see self::available()} is how many more units can be sold: `null`
 *   when unlimited (no row, untracked, or backorders allowed), otherwise
 *   on-hand less reserved, plus whatever `$holder` (a cart in checkout)
 *   already has reserved, so a shopper's own hold doesn't count against
 *   them.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Inventory;

use ArtisanPackUI\Ecommerce\Contracts\ExpandsInventory;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class StockLevels
{
    /**
     * The stock `$quantity` units of `$product` / `$variant` consume.
     *
     * @since 1.0.0
     *
     * @param  Product              $product   Product.
     * @param  ProductVariant|null  $variant   Variant.
     * @param  int                  $quantity  Units.
     *
     * @return array<int, array{stockable: Product|ProductVariant, quantity: int}>
     */
    public function components( Product $product, ?ProductVariant $variant, int $quantity ): array
    {
        if ( $product->typeIsMissing() ) {
            return [];
        }

        $type = $product->productType();

        if ( $type instanceof ExpandsInventory ) {
            return $type->inventoryComponents( $product, $variant, $quantity );
        }

        if ( ! $type->isInventoryTracked() ) {
            return [];
        }

        return [ [ 'stockable' => $variant ?? $product, 'quantity' => $quantity ] ];
    }

    /**
     * The inventory row of a stockable in the default warehouse, if any.
     *
     * With `$preferLoaded`, a loaded `inventoryItems` relation is used
     * instead of a query (`Product::withDisplayData()`). Only display code
     * opts in; reservations, checkout, and refunds always read the row.
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $stockable     Product or variant.
     * @param  bool                    $preferLoaded  Use a loaded `inventoryItems` relation.
     *
     * @return InventoryItem|null
     */
    public function itemFor( Product|ProductVariant $stockable, bool $preferLoaded = false ): ?InventoryItem
    {
        if ( $preferLoaded && $stockable->relationLoaded( 'inventoryItems' ) ) {
            return $stockable->inventoryItems->first( static fn ( InventoryItem $item ): bool => InventoryItem::DEFAULT_WAREHOUSE === (int) $item->warehouse_id );
        }

        return InventoryItem::query()
            ->where( 'stockable_type', $stockable->getMorphClass() )
            ->where( 'stockable_id', $stockable->getKey() )
            ->where( 'warehouse_id', InventoryItem::DEFAULT_WAREHOUSE )
            ->first();
    }

    /**
     * How many more units of `$stockable` can be sold, or null when there is
     * no limit.
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $stockable     Product or variant.
     * @param  Model|null              $holder        Whose own reservations to add back.
     * @param  bool                    $preferLoaded  Use a loaded `inventoryItems` relation ({@see self::itemFor()}).
     *
     * @return int|null
     */
    public function available( Product|ProductVariant $stockable, ?Model $holder = null, bool $preferLoaded = false ): ?int
    {
        $item = $this->itemFor( $stockable, $preferLoaded );

        if ( null === $item || ! $item->track_inventory || $item->allow_backorder ) {
            return null;
        }

        $held = null === $holder ? 0 : (int) InventoryReservation::query()
            ->where( 'inventory_item_id', $item->id )
            ->where( 'reservable_type', $holder->getMorphClass() )
            ->where( 'reservable_id', $holder->getKey() )
            ->sum( 'quantity' );

        return max( 0, $item->availableQuantity() + $held );
    }

    /**
     * How many units of `$product` / `$variant` can be sold: the least any
     * component allows. Null when nothing limits it.
     *
     * @since 1.0.0
     *
     * @param  Product              $product       Product.
     * @param  ProductVariant|null  $variant       Variant.
     * @param  Model|null           $holder        Whose own reservations to add back.
     * @param  bool                 $preferLoaded  Use loaded `inventoryItems` relations ({@see self::itemFor()}).
     *
     * @return int|null
     */
    public function sellable( Product $product, ?ProductVariant $variant, ?Model $holder = null, bool $preferLoaded = false ): ?int
    {
        $limit = null;

        foreach ( $this->components( $product, $variant, 1 ) as $component ) {
            $available = $this->available( $component['stockable'], $holder, $preferLoaded );

            if ( null === $available ) {
                continue;
            }

            $units = intdiv( $available, max( 1, $component['quantity'] ) );
            $limit = null === $limit ? $units : min( $limit, $units );
        }

        return $limit;
    }
}
