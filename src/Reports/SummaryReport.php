<?php

/**
 * SummaryReport.
 *
 * Dashboard KPIs (engine issue #146): sales today and over the last 30 days
 * (both including today, in the store time zone), orders awaiting
 * fulfillment, low-stock items, and reviews waiting for moderation. Point
 * in time: it takes no date range.
 *
 * `sales_today` / `sales_30_days` are {@see SalesReport} `total`s and
 * `net_sales_*` its `net`s, so the dashboard and the sales report agree.
 * "Awaiting fulfillment" is a `processing` order that is unfulfilled or
 * partly fulfilled; "low stock" is {@see LowStockReport::query()}.
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

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\ProductReview;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SummaryReport extends Report
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
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  ReportRange|null      $range    Ignored.
     * @param  array<string, mixed>  $options  Unused.
     *
     * @return array<string, mixed>
     */
    public function run( ?ReportRange $range, array $options = [] ): array
    {
        $sales  = app( SalesReport::class );
        $month  = $sales->run( ReportRange::lastDays( 30 ) );
        $today  = $month['series'][ array_key_last( $month['series'] ) ] ?? [ 'total' => 0, 'net' => 0, 'orders' => 0 ];

        return [
            'currency' => $month['currency'],
            'timezone' => $month['timezone'],
            'range'    => null,
            'totals'   => [
                'sales_today'          => $today['total'],
                'net_sales_today'      => $today['net'],
                'orders_today'         => $today['orders'],
                'sales_30_days'        => $month['totals']['total'],
                'net_sales_30_days'    => $month['totals']['net'],
                'orders_30_days'       => $month['totals']['orders'],
                'awaiting_fulfillment' => Order::query()
                    ->where( 'system_status', 'processing' )
                    ->whereIn( 'fulfillment_status', [ 'unfulfilled', 'partial' ] )
                    ->count(),
                'low_stock'            => LowStockReport::query()->count(),
                'pending_reviews'      => ProductReview::query()->where( 'status', ProductReview::STATUS_PENDING )->count(),
            ],
            'notices'  => $month['notices'],
        ];
    }
}
