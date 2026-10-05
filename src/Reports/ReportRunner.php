<?php

/**
 * ReportRunner.
 *
 * Runs a registered report (engine issue #146), adding the previous-period
 * comparison when the range asks for it and passing the result through the
 * `ap.ecommerce.reports.result` filter (`array $result, string $key,
 * ?ReportRange $range, array $options`).
 *
 * The result is the report's own array plus `report` (key), `label`, and
 * `previous` — the same report run over {@see ReportRange::previous()}, or
 * null when not comparing or the report is point-in-time.
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

use ArtisanPackUI\Ecommerce\Registries\ReportRegistry;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ReportRunner
{
    /**
     * @since 1.0.0
     *
     * @param  ReportRegistry  $reports  Registered reports.
     */
    public function __construct( private readonly ReportRegistry $reports )
    {
    }

    /**
     * Runs `$key`.
     *
     * @since 1.0.0
     *
     * @param  string                $key      Report key.
     * @param  ReportRange|null      $range    Range; defaults to the last 30 days for ranged reports.
     * @param  array<string, mixed>  $options  Report options (`limit`, `sort`, …).
     *
     * @throws InvalidArgumentException When the report is not registered.
     *
     * @return array<string, mixed>
     */
    public function run( string $key, ?ReportRange $range = null, array $options = [] ): array
    {
        if ( ! $this->reports->has( $key ) ) {
            throw new InvalidArgumentException( __( 'Report ":report" is not registered.', [ 'report' => $key ] ) );
        }

        $report = $this->reports->get( $key );
        $range  = $report->ranged() ? ( $range ?? ReportRange::make() ) : null;
        $result = [
            'report'   => $key,
            'label'    => (string) ( $this->reports->meta( $key )['label'] ?? $key ),
            ...$report->run( $range, $options ),
            'previous' => null !== $range && $range->compare ? $report->run( $range->previous(), $options ) : null,
        ];

        return (array) applyFilters( 'ap.ecommerce.reports.result', $result, $key, $range, $options );
    }

    /**
     * The registered reports for a picker: key, label, and whether each
     * takes a date range.
     *
     * @since 1.0.0
     *
     * @return array<int, array{key: string, label: string, ranged: bool}>
     */
    public function available(): array
    {
        return array_map( fn ( string $key ): array => [
            'key'    => $key,
            'label'  => (string) ( $this->reports->meta( $key )['label'] ?? $key ),
            'ranged' => $this->reports->get( $key )->ranged(),
        ], $this->reports->ordered() );
    }
}
