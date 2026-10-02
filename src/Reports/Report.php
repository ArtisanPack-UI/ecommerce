<?php

/**
 * Report.
 *
 * Base class for the engine's report queries (engine issue #146, parent
 * plan §10.2 item 7). Each report returns a plain array so the Livewire
 * admin, the REST endpoint, and satellites such as
 * `ecommerce-reports-pro` all read the same numbers.
 *
 * Every result carries:
 *
 * - `currency` — the store's current base currency; all amounts are
 *   integer minor units of it.
 * - `timezone` and `range` — the store time zone and the range run
 *   (`range` is null for point-in-time reports).
 * - `notices` — `converted_orders` (orders whose snapshot base currency
 *   differs from today's, converted at today's cross-rate and to be flagged
 *   in the UI) and `unconverted_orders` (left out: no cross-rate).
 *
 * Reports are registered in {@see \ArtisanPackUI\Ecommerce\Registries\ReportRegistry}
 * and run through {@see ReportRunner}, which adds the previous-period
 * comparison. {@see \ArtisanPackUI\Ecommerce\Policies\ReportPolicy} is
 * registered for this class, so `$user->can( 'view', Report::class )`
 * checks `ecommerce.report.view`.
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

use ArtisanPackUI\Ecommerce\Services\CurrencyConverter;
use Closure;
use Illuminate\Support\Collection;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class Report
{
    /**
     * Payment statuses whose orders count as sales.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const SALE_PAYMENT_STATUSES = [ 'paid', 'partially_refunded', 'refunded' ];

    /**
     * Whether the report takes a date range. Point-in-time reports
     * (inventory) return false and receive a null range.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function ranged(): bool
    {
        return true;
    }

    /**
     * Validation rules for the report's own options (`limit`, `sort`, …).
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function optionRules(): array
    {
        return [];
    }

    /**
     * Runs the report.
     *
     * @since 1.0.0
     *
     * @param  ReportRange|null      $range    Range, or null for point-in-time reports.
     * @param  array<string, mixed>  $options  Validated options.
     *
     * @return array<string, mixed>
     */
    abstract public function run( ?ReportRange $range, array $options = [] ): array;

    /**
     * Runs `$query` for ids in chunks of 1,000, so a long range over a large
     * catalog stays under the database's bound-parameter limit.
     *
     * @since 1.0.0
     *
     * @param  array<int, int|string>                $ids    Ids.
     * @param  Closure(array<int, int|string>): iterable<int, mixed>  $query  Fetches the rows for one chunk.
     *
     * @return Collection<int, mixed>
     */
    protected static function inChunks( array $ids, Closure $query ): Collection
    {
        $rows = collect();

        foreach ( array_chunk( array_values( array_unique( $ids ) ), 1000 ) as $chunk ) {
            $rows = $rows->concat( $query( $chunk ) );
        }

        return $rows;
    }

    /**
     * A fresh converter for one run.
     *
     * @since 1.0.0
     *
     * @return BaseAmounts
     */
    protected function amounts(): BaseAmounts
    {
        return new BaseAmounts( app( CurrencyConverter::class ) );
    }

    /**
     * Wraps report data in the shared envelope.
     *
     * @since 1.0.0
     *
     * @param  ReportRange|null      $range    Range run.
     * @param  BaseAmounts           $amounts  Converter used (for currency and notices).
     * @param  array<string, mixed>  $data     Report-specific keys (`totals`, `rows`, `series`, …).
     *
     * @return array<string, mixed>
     */
    protected function result( ?ReportRange $range, BaseAmounts $amounts, array $data ): array
    {
        return [
            'currency' => $amounts->base,
            'timezone' => ReportRange::timezone(),
            'range'    => $range?->toArray(),
            ...$data,
            'notices'  => $amounts->notices(),
        ];
    }
}
