<?php

/**
 * LowStockReport.
 *
 * Tracked items whose available stock (on hand − reserved) is at or below
 * their low-stock threshold — a reorder list (engine issue #146). Items
 * without a threshold never appear. Point in time: it takes no date range.
 * Row keys are described on {@see InventoryReport}, plus `shortfall`
 * (`threshold − available`, at least 0).
 *
 * Rows are sorted by how far below threshold they are, worst first.
 * Option: `limit` (1–1000, default 100); `totals.items` counts them all.
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class LowStockReport extends InventoryReport
{
    /**
     * Tracked items at or below their threshold. Shared with the dashboard
     * summary so the badge and the report agree.
     *
     * @since 1.0.0
     *
     * @return Builder<InventoryItem>
     */
    public static function query(): Builder
    {
        return InventoryItem::query()
            ->where( 'track_inventory', true )
            ->whereNotNull( 'low_stock_threshold' )
            // `available <= threshold`, written without subtracting the
            // unsigned `quantity_reserved` (MySQL errors when it goes negative).
            ->whereRaw( 'quantity_on_hand <= low_stock_threshold + quantity_reserved' );
    }

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
            'limit' => [ 'nullable', 'integer', 'min:1', 'max:1000' ],
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  ReportRange|null      $range    Ignored.
     * @param  array<string, mixed>  $options  `limit`.
     *
     * @return array<string, mixed>
     */
    public function run( ?ReportRange $range, array $options = [] ): array
    {
        $amounts = $this->amounts();
        $limit   = max( 1, min( 1000, (int) ( $options['limit'] ?? 100 ) ) );
        $rows    = [];

        self::query()->chunkById( 500, function ( Collection $items ) use ( $amounts, &$rows ): void {
            /** @var Collection<int, InventoryItem> $items */
            foreach ( $this->describe( $items, $amounts->base ) as $row ) {
                $rows[] = [ ...$row, 'shortfall' => max( 0, (int) $row['low_stock_threshold'] - $row['available'] ) ];
            }
        } );

        usort( $rows, static fn ( array $a, array $b ): int => [ $a['available'] - (int) $a['low_stock_threshold'], $a['name'] ] <=> [ $b['available'] - (int) $b['low_stock_threshold'], $b['name'] ] );

        return $this->result( null, $amounts, [
            'totals' => [ 'items' => count( $rows ) ],
            'rows'   => array_slice( $rows, 0, $limit ),
        ] );
    }
}
