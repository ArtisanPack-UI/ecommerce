<?php

/**
 * InventoryReport.
 *
 * Base for the point-in-time stock reports (engine issue #146): inventory
 * levels and low stock. They take no date range. Each row describes one
 * tracked `inventory_items` row:
 *
 * | Key                   | Meaning                                                    |
 * |-----------------------|------------------------------------------------------------|
 * | `inventory_item_id`   | Inventory row id                                           |
 * | `stockable_type`      | `product` or `variant`                                     |
 * | `stockable_id`        | Product or variant id                                      |
 * | `product_id`          | Owning product id (the product itself for `product` rows)  |
 * | `name`, `sku`         | Current catalog name ("Product — Variant") and SKU         |
 * | `on_hand`, `reserved`, `available` | Quantities; `available = on_hand − reserved`  |
 * | `low_stock_threshold` | Threshold, or null                                         |
 * | `allow_backorder`     | Whether the item can be oversold                           |
 * | `unit_cost`           | Current cost in the base currency, or null when none is set |
 * | `stock_value`         | `max( on_hand, 0 ) × unit_cost`, or null without a cost    |
 *
 * Costs come from the current `product_prices` row in the base currency
 * (a variant without its own row uses its product's).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Reports;

use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class InventoryReport extends Report
{
    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function ranged(): bool
    {
        return false;
    }

    /**
     * Tracked inventory rows.
     *
     * @since 1.0.0
     *
     * @return Builder<InventoryItem>
     */
    protected function trackedItems(): Builder
    {
        return InventoryItem::query()->where( 'track_inventory', true );
    }

    /**
     * Describes a chunk of inventory rows.
     *
     * @since 1.0.0
     *
     * @param  Collection<int, InventoryItem>  $items  Rows.
     * @param  string                          $base   Base currency.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function describe( Collection $items, string $base ): array
    {
        $productMorph = ( new Product() )->getMorphClass();
        $variantMorph = ( new ProductVariant() )->getMorphClass();

        $variants = ProductVariant::query()
            ->whereIn( 'id', $items->where( 'stockable_type', $variantMorph )->pluck( 'stockable_id' )->all() )
            ->get( [ 'id', 'product_id', 'name', 'sku' ] )
            ->keyBy( 'id' );

        $productIds = $items->where( 'stockable_type', $productMorph )->pluck( 'stockable_id' )->merge( $variants->pluck( 'product_id' ) )->unique()->values()->all();
        $products   = Product::query()->whereIn( 'id', $productIds )->get( [ 'id', 'name', 'sku' ] )->keyBy( 'id' );
        $costs      = $this->costs( $base, $productIds, $variants->keys()->all() );
        $rows       = [];

        foreach ( $items as $item ) {
            $isVariant = $variantMorph === $item->stockable_type;
            $variant   = $isVariant ? ( $variants[ $item->stockable_id ] ?? null ) : null;
            $productId = $isVariant ? $variant?->product_id : (int) $item->stockable_id;
            $product   = null === $productId ? null : ( $products[ $productId ] ?? null );
            $unitCost  = $isVariant
                ? ( $costs[ $variantMorph ][ (int) $item->stockable_id ] ?? ( null === $productId ? null : ( $costs[ $productMorph ][ (int) $productId ] ?? null ) ) )
                : ( $costs[ $productMorph ][ (int) $item->stockable_id ] ?? null );
            $onHand    = (int) $item->quantity_on_hand;
            $reserved  = (int) $item->quantity_reserved;
            $rows[]    = [
                'inventory_item_id'   => (int) $item->id,
                'stockable_type'      => $isVariant ? 'variant' : 'product',
                'stockable_id'        => (int) $item->stockable_id,
                'product_id'          => null === $productId ? null : (int) $productId,
                'name'                => self::name( $product, $variant ),
                'sku'                 => $variant?->sku ?? $product?->sku,
                'on_hand'             => $onHand,
                'reserved'            => $reserved,
                'available'           => $onHand - $reserved,
                'low_stock_threshold' => null === $item->low_stock_threshold ? null : (int) $item->low_stock_threshold,
                'allow_backorder'     => (bool) $item->allow_backorder,
                'unit_cost'           => $unitCost,
                'stock_value'         => null === $unitCost ? null : max( 0, $onHand ) * $unitCost,
            ];
        }

        return $rows;
    }

    /**
     * Current unit costs in the base currency: `[morph class][id] => cost`.
     *
     * @since 1.0.0
     *
     * @param  string           $base        Base currency.
     * @param  array<int, int>  $productIds  Product ids.
     * @param  array<int, int>  $variantIds  Variant ids.
     *
     * @return array<string, array<int, int>>
     */
    protected function costs( string $base, array $productIds, array $variantIds ): array
    {
        $productMorph = ( new Product() )->getMorphClass();
        $variantMorph = ( new ProductVariant() )->getMorphClass();
        $costs        = [ $productMorph => [], $variantMorph => [] ];

        ProductPrice::query()
            ->currentAt()
            ->where( 'currency', $base )
            ->whereNotNull( 'cost_amount' )
            ->where( function ( Builder $query ) use ( $productMorph, $variantMorph, $productIds, $variantIds ): void {
                $query->where( fn ( Builder $q ) => $q->where( 'priceable_type', $productMorph )->whereIn( 'priceable_id', $productIds ) )
                    ->orWhere( fn ( Builder $q ) => $q->where( 'priceable_type', $variantMorph )->whereIn( 'priceable_id', $variantIds ) );
            } )
            ->orderByDesc( 'starts_at' )
            ->orderByDesc( 'id' )
            ->toBase()
            ->get( [ 'priceable_type', 'priceable_id', 'cost_amount' ] )
            ->each( static function ( object $price ) use ( &$costs ): void {
                $costs[ $price->priceable_type ][ (int) $price->priceable_id ] ??= (int) $price->cost_amount;
            } );

        return $costs;
    }

    /**
     * "Product — Variant", or whichever part exists.
     *
     * @since 1.0.0
     *
     * @param  Product|null         $product  Product.
     * @param  ProductVariant|null  $variant  Variant.
     *
     * @return string
     */
    protected static function name( ?Product $product, ?ProductVariant $variant ): string
    {
        $parts = array_filter( [ $product?->name, $variant?->name ?? $variant?->sku ], static fn ( $part ): bool => is_string( $part ) && '' !== $part );

        return [] === $parts ? __( 'Deleted product' ) : implode( ' — ', $parts );
    }
}
