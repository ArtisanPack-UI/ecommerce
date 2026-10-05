<?php

/**
 * TopProductsReport.
 *
 * Best-selling products by units and net revenue, with a per-variant
 * breakdown (engine issue #146). Lines whose product has since been deleted
 * are grouped by their snapshot name.
 *
 * Options: `sort` (`net_revenue` — the default — or `units`) and `limit`
 * (1–100, default 10). `totals` cover every product, not just the rows
 * returned.
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

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class TopProductsReport extends LineItemReport
{
    /**
     * Sort keys.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const SORTS = [ 'net_revenue', 'units' ];

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function optionRules(): array
    {
        return [
            'sort'  => [ 'nullable', Rule::in( self::SORTS ) ],
            'limit' => [ 'nullable', 'integer', 'min:1', 'max:100' ],
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  ReportRange|null      $range    Range.
     * @param  array<string, mixed>  $options  `sort`, `limit`.
     *
     * @throws InvalidArgumentException Without a range.
     *
     * @return array<string, mixed>
     */
    public function run( ?ReportRange $range, array $options = [] ): array
    {
        if ( null === $range ) {
            throw new InvalidArgumentException( __( 'The top products report needs a date range.' ) );
        }

        $amounts  = $this->amounts();
        $products = [];
        $orders   = [];

        $this->eachSoldLine( $range, $amounts, static function ( array $line ) use ( &$products, &$orders ): void {
            $key = null === $line['product_id'] ? 'deleted:' . (string) ( $line['snapshot']['name'] ?? '' ) : (string) $line['product_id'];

            $products[ $key ] ??= [
                'product_id'  => $line['product_id'],
                'name'        => (string) ( $line['snapshot']['name'] ?? '' ),
                'sku'         => $line['snapshot']['sku'] ?? null,
                'units'       => 0,
                'net_revenue' => 0,
                'orders'      => [],
                'variants'    => [],
            ];

            $products[ $key ]['units'] += $line['units'];
            $products[ $key ]['net_revenue'] += $line['net'];
            $products[ $key ]['orders'][ $line['order_id'] ] = true;
            $orders[ $line['order_id'] ]                     = true;

            if ( null !== $line['variant_id'] ) {
                $variant = &$products[ $key ]['variants'][ $line['variant_id'] ];
                $variant ??= [
                    'variant_id'  => $line['variant_id'],
                    'name'        => self::snapshotVariantName( $line['snapshot'] ),
                    'sku'         => $line['snapshot']['sku'] ?? null,
                    'units'       => 0,
                    'net_revenue' => 0,
                ];

                $variant['units'] += $line['units'];
                $variant['net_revenue'] += $line['net'];
                unset( $variant );
            }
        } );

        $sort  = in_array( $options['sort'] ?? null, self::SORTS, true ) ? (string) $options['sort'] : 'net_revenue';
        $limit = max( 1, min( 100, (int) ( $options['limit'] ?? 10 ) ) );
        $rows  = $this->present( $products, $sort );

        return $this->result( $range, $amounts, [
            'sort'   => $sort,
            'totals' => [
                'products'    => count( $rows ),
                'units'       => array_sum( array_column( $rows, 'units' ) ),
                'net_revenue' => array_sum( array_column( $rows, 'net_revenue' ) ),
                'orders'      => count( $orders ),
            ],
            'rows'   => array_slice( $rows, 0, $limit ),
        ] );
    }

    /**
     * Sorts the products, names them from the catalog, and flattens variants.
     *
     * @since 1.0.0
     *
     * @param  array<string, array<string, mixed>>  $products  Accumulated rows.
     * @param  string                               $sort      Sort key.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function present( array $products, string $sort ): array
    {
        $productIds = array_values( array_filter( array_column( $products, 'product_id' ) ) );
        $variantIds = [];

        foreach ( $products as $product ) {
            $variantIds = [ ...$variantIds, ...array_keys( $product['variants'] ) ];
        }

        $names    = self::inChunks( $productIds, static fn ( array $ids ) => Product::query()->whereIn( 'id', $ids )->get( [ 'id', 'name', 'sku' ] ) )->keyBy( 'id' );
        $variants = self::inChunks( $variantIds, static fn ( array $ids ) => ProductVariant::query()->whereIn( 'id', $ids )->get( [ 'id', 'name', 'sku' ] ) )->keyBy( 'id' );
        $rows     = [];

        foreach ( $products as $product ) {
            $current = null === $product['product_id'] ? null : ( $names[ $product['product_id'] ] ?? null );
            $rows[]  = [
                'product_id'  => $product['product_id'],
                'name'        => null !== $current ? (string) $current->name : ( '' !== $product['name'] ? $product['name'] : __( 'Deleted product' ) ),
                'sku'         => null !== $current ? $current->sku : $product['sku'],
                'units'       => $product['units'],
                'net_revenue' => $product['net_revenue'],
                'orders'      => count( $product['orders'] ),
                'variants'    => $this->presentVariants( $product['variants'], $variants, $sort ),
            ];
        }

        usort( $rows, static fn ( array $a, array $b ): int => [ $b[ $sort ], $a['name'] ] <=> [ $a[ $sort ], $b['name'] ] );

        return $rows;
    }

    /**
     * Names and sorts one product's variants.
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>  $accumulated  Variants by id.
     * @param  iterable<int, ProductVariant>     $catalog      Current variants by id.
     * @param  string                            $sort         Sort key.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function presentVariants( array $accumulated, iterable $catalog, string $sort ): array
    {
        $rows = [];

        foreach ( $accumulated as $id => $variant ) {
            $current = $catalog[ $id ] ?? null;
            $rows[]  = [
                ...$variant,
                'name' => null !== $current && filled( $current->name ) ? (string) $current->name : $variant['name'],
                'sku'  => null !== $current ? $current->sku : $variant['sku'],
            ];
        }

        usort( $rows, static fn ( array $a, array $b ): int => $b[ $sort ] <=> $a[ $sort ] );

        return $rows;
    }

    /**
     * A variant label from a line snapshot: its options ("Red / L"), else
     * its SKU, else its name.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $snapshot  `order_items.product_snapshot`.
     *
     * @return string
     */
    protected static function snapshotVariantName( array $snapshot ): string
    {
        $options = array_filter( array_map( 'strval', array_filter( (array) ( $snapshot['options'] ?? [] ), 'is_scalar' ) ) );

        if ( [] !== $options ) {
            return implode( ' / ', $options );
        }

        return (string) ( $snapshot['sku'] ?? $snapshot['name'] ?? '' );
    }
}
