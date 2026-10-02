<?php

/**
 * SalesReport.
 *
 * Sales over time (engine issue #146, parent plan §10.2 item 7). Counts
 * orders placed in the range whose payment status is a sale
 * ({@see Report::SALE_PAYMENT_STATUSES}), bucketed by day, week, or month
 * in the store time zone. Refunds are counted on the day they were issued,
 * not the day the order was placed.
 *
 * Per bucket and in `totals`:
 *
 * | Key                   | Meaning                                                |
 * |-----------------------|--------------------------------------------------------|
 * | `gross`               | Σ order subtotals (before discounts)                   |
 * | `discounts`           | Σ order discounts                                      |
 * | `refunds`             | Σ refunds issued in the bucket                         |
 * | `net`                 | `gross − discounts − refunds`                          |
 * | `tax`                 | Σ order tax                                            |
 * | `shipping`            | Σ order shipping                                       |
 * | `total`               | Σ order totals (`gross − discounts + tax + shipping`)  |
 * | `orders`              | Number of orders                                       |
 * | `average_order_value` | `total ÷ orders`, rounded; 0 with no orders            |
 *
 * Amounts are minor units of the current base currency (see {@see BaseAmounts}).
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
use ArtisanPackUI\Ecommerce\Models\Refund;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SalesReport extends Report
{
    /**
     * The metrics in each bucket, in display order.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const METRICS = [ 'gross', 'discounts', 'refunds', 'net', 'tax', 'shipping', 'total', 'orders', 'average_order_value' ];

    /**
     * Order amount columns summed per bucket.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    protected const ORDER_COLUMNS = [
        'gross'     => 'subtotal_amount',
        'discounts' => 'discount_amount',
        'tax'       => 'tax_amount',
        'shipping'  => 'shipping_amount',
        'total'     => 'total_amount',
    ];

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
            throw new InvalidArgumentException( 'The sales report needs a date range.' );
        }

        $amounts = $this->amounts();
        $buckets = $range->buckets();
        $series  = array_map( static fn (): array => self::emptyBucket(), $buckets );
        $bounds  = $range->queryBounds();

        Order::query()
            ->toBase()
            ->select( [ 'id', 'placed_at', 'currency', 'base_currency', 'fx_rate_to_base_e8', ...array_values( self::ORDER_COLUMNS ) ] )
            ->whereNotNull( 'placed_at' )
            ->whereBetween( 'placed_at', $bounds )
            ->whereIn( 'payment_status', self::SALE_PAYMENT_STATUSES )
            ->lazyById( 1000 )
            ->each( function ( object $order ) use ( $range, $amounts, &$series ): void {
                $bucket = $range->bucketKey( (string) $order->placed_at );

                if ( ! isset( $series[ $bucket ] ) ) {
                    return;
                }

                $converted = [];

                foreach ( self::ORDER_COLUMNS as $metric => $column ) {
                    $converted[ $metric ] = $amounts->toBase( $order->{$column}, (string) $order->currency, (string) $order->base_currency, $order->fx_rate_to_base_e8, $order->id );

                    // No cross-rate: leave the whole order out, as the other reports do.
                    if ( null === $converted[ $metric ] ) {
                        return;
                    }
                }

                foreach ( $converted as $metric => $amount ) {
                    $series[ $bucket ][ $metric ] += $amount;
                }

                $series[ $bucket ]['orders']++;
            } );

        $refunds = ( new Refund() )->getTable();
        $orders  = ( new Order() )->getTable();

        Refund::query()
            ->toBase()
            ->join( $orders, "{$orders}.id", '=', "{$refunds}.order_id" )
            ->select( [ "{$refunds}.id", "{$refunds}.amount", "{$refunds}.currency", "{$refunds}.created_at", "{$refunds}.order_id", "{$orders}.base_currency", "{$orders}.fx_rate_to_base_e8" ] )
            ->whereBetween( "{$refunds}.created_at", $bounds )
            ->lazyById( 1000, "{$refunds}.id", 'id' )
            ->each( function ( object $refund ) use ( $range, $amounts, &$series ): void {
                $bucket = $range->bucketKey( (string) $refund->created_at );

                if ( isset( $series[ $bucket ] ) ) {
                    $series[ $bucket ]['refunds'] += (int) $amounts->toBase( $refund->amount, (string) $refund->currency, (string) $refund->base_currency, $refund->fx_rate_to_base_e8, $refund->order_id );
                }
            } );

        $totals = self::emptyBucket();
        $rows   = [];

        foreach ( $series as $key => $bucket ) {
            $bucket = self::finish( $bucket );

            foreach ( self::METRICS as $metric ) {
                if ( 'average_order_value' !== $metric && 'net' !== $metric ) {
                    $totals[ $metric ] += $bucket[ $metric ];
                }
            }

            $rows[] = [ 'period' => $key, 'start' => $buckets[ $key ], ...$bucket ];
        }

        return $this->result( $range, $amounts, [
            'totals' => self::finish( $totals ),
            'series' => $rows,
        ] );
    }

    /**
     * A bucket with every metric at zero.
     *
     * @since 1.0.0
     *
     * @return array<string, int>
     */
    protected static function emptyBucket(): array
    {
        return array_fill_keys( self::METRICS, 0 );
    }

    /**
     * Fills the derived metrics (`net`, `average_order_value`).
     *
     * @since 1.0.0
     *
     * @param  array<string, int>  $bucket  Summed bucket.
     *
     * @return array<string, int>
     */
    protected static function finish( array $bucket ): array
    {
        $bucket['net']                 = $bucket['gross'] - $bucket['discounts'] - $bucket['refunds'];
        $bucket['average_order_value'] = $bucket['orders'] > 0 ? (int) round( $bucket['total'] / $bucket['orders'] ) : 0;

        return $bucket;
    }
}
