<?php

/**
 * CategoryRevenueReport.
 *
 * Net revenue and units by product category (engine issue #146). A line
 * counts toward every category its product is in today, so when products
 * sit in several categories the rows add up to more than `totals`, which
 * counts each line once. Lines whose product has no category (or has been
 * deleted) fall in an "Uncategorized" row with a null `category_id`.
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

use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CategoryRevenueReport extends LineItemReport
{
    /**
     * Pivot table linking products to categories.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected const PIVOT = 'ecommerce_product_category_product';

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  ReportRange|null      $range    Range.
     * @param  array<string, mixed>  $options  Unused.
     *
     * @throws InvalidArgumentException Without a range.
     *
     * @return array<string, mixed>
     */
    public function run( ?ReportRange $range, array $options = [] ): array
    {
        if ( null === $range ) {
            throw new InvalidArgumentException( 'The revenue by category report needs a date range.' );
        }

        $amounts   = $this->amounts();
        $byProduct = [];
        $totals    = [ 'units' => 0, 'net_revenue' => 0, 'orders' => [] ];

        $this->eachSoldLine( $range, $amounts, static function ( array $line ) use ( &$byProduct, &$totals ): void {
            $key = $line['product_id'] ?? 0;

            $byProduct[ $key ] ??= [ 'units' => 0, 'net_revenue' => 0, 'orders' => [] ];

            $byProduct[ $key ]['units'] += $line['units'];
            $byProduct[ $key ]['net_revenue'] += $line['net'];
            $byProduct[ $key ]['orders'][ $line['order_id'] ] = true;
            $totals['units'] += $line['units'];
            $totals['net_revenue'] += $line['net'];
            $totals['orders'][ $line['order_id'] ]            = true;
        } );

        $connection = DB::connection( ( new ProductCategory() )->getConnectionName() );
        $links      = self::inChunks(
            array_keys( array_filter( $byProduct, static fn ( $value, $key ): bool => 0 !== $key, ARRAY_FILTER_USE_BOTH ) ),
            static fn ( array $ids ) => $connection->table( self::PIVOT )->whereIn( 'product_id', $ids )->get( [ 'product_id', 'product_category_id' ] ),
        )->groupBy( 'product_id' );

        $categories = [];

        foreach ( $byProduct as $productId => $sold ) {
            $categoryIds = isset( $links[ $productId ] ) ? $links[ $productId ]->pluck( 'product_category_id' )->map( 'intval' )->all() : [ null ];

            foreach ( $categoryIds as $categoryId ) {
                $key = null === $categoryId ? 'none' : (string) $categoryId;

                $categories[ $key ] ??= [ 'category_id' => $categoryId, 'units' => 0, 'net_revenue' => 0, 'orders' => [] ];

                $categories[ $key ]['units'] += $sold['units'];
                $categories[ $key ]['net_revenue'] += $sold['net_revenue'];
                $categories[ $key ]['orders'] += $sold['orders'];
            }
        }

        $names = self::inChunks(
            array_filter( array_column( $categories, 'category_id' ) ),
            static fn ( array $ids ) => ProductCategory::query()->whereIn( 'id', $ids )->get( [ 'id', 'name', 'parent_id' ] ),
        )->keyBy( 'id' );

        $rows = [];

        foreach ( $categories as $category ) {
            $current = null === $category['category_id'] ? null : ( $names[ $category['category_id'] ] ?? null );
            $rows[]  = [
                'category_id' => $category['category_id'],
                'parent_id'   => null === $current?->parent_id ? null : (int) $current->parent_id,
                'name'        => null !== $current ? (string) $current->name : __( 'Uncategorized' ),
                'units'       => $category['units'],
                'net_revenue' => $category['net_revenue'],
                'orders'      => count( $category['orders'] ),
                'share'       => 0 === $totals['net_revenue'] ? 0.0 : round( $category['net_revenue'] / $totals['net_revenue'], 4 ),
            ];
        }

        usort( $rows, static fn ( array $a, array $b ): int => [ $b['net_revenue'], $a['name'] ] <=> [ $a['net_revenue'], $b['name'] ] );

        return $this->result( $range, $amounts, [
            'totals' => [
                'units'       => $totals['units'],
                'net_revenue' => $totals['net_revenue'],
                'orders'      => count( $totals['orders'] ),
                'categories'  => count( $rows ),
            ],
            'rows'   => $rows,
        ] );
    }
}
