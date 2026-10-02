<?php

/**
 * InventoryLevelsReport.
 *
 * Stock on hand, reserved in open checkouts, available, and stock value at
 * cost for every tracked item (engine issue #146). Point in time: it takes
 * no date range. Row keys are described on {@see InventoryReport}.
 *
 * Options: `sort` (`stock_value` — the default — `available`, `on_hand`,
 * `reserved`, or `name`) and `limit` (1–1000, default 100). `totals` cover
 * every tracked item, not just the rows returned; `items_without_cost`
 * counts items left out of `stock_value` because they have no cost in the
 * base currency.
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
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class InventoryLevelsReport extends InventoryReport
{
    /**
     * Sort keys.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const SORTS = [ 'stock_value', 'available', 'on_hand', 'reserved', 'name' ];

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
            'limit' => [ 'nullable', 'integer', 'min:1', 'max:1000' ],
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  ReportRange|null      $range    Ignored.
     * @param  array<string, mixed>  $options  `sort`, `limit`.
     *
     * @return array<string, mixed>
     */
    public function run( ?ReportRange $range, array $options = [] ): array
    {
        $amounts = $this->amounts();
        $sort    = in_array( $options['sort'] ?? null, self::SORTS, true ) ? (string) $options['sort'] : 'stock_value';
        $limit   = max( 1, min( 1000, (int) ( $options['limit'] ?? 100 ) ) );
        $totals  = [ 'items' => 0, 'on_hand' => 0, 'reserved' => 0, 'available' => 0, 'stock_value' => 0, 'items_without_cost' => 0 ];
        $rows    = [];

        $this->trackedItems()->chunkById( 500, function ( Collection $items ) use ( $amounts, &$totals, &$rows ): void {
            /** @var Collection<int, InventoryItem> $items */
            foreach ( $this->describe( $items, $amounts->base ) as $row ) {
                $totals['items']++;
                $totals['on_hand'] += $row['on_hand'];
                $totals['reserved'] += $row['reserved'];
                $totals['available'] += $row['available'];

                if ( null === $row['stock_value'] ) {
                    $totals['items_without_cost']++;
                } else {
                    $totals['stock_value'] += $row['stock_value'];
                }

                $rows[] = $row;
            }
        } );

        usort( $rows, static fn ( array $a, array $b ): int => 'name' === $sort
            ? strnatcasecmp( $a['name'], $b['name'] )
            : [ $b[ $sort ] ?? -1, $a['name'] ] <=> [ $a[ $sort ] ?? -1, $b['name'] ] );

        return $this->result( null, $amounts, [
            'sort'   => $sort,
            'totals' => $totals,
            'rows'   => array_slice( $rows, 0, $limit ),
        ] );
    }
}
