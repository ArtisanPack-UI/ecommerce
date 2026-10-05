<?php

/**
 * RefundSplit.
 *
 * The refunds a report nets out (audit D16): succeeded refunds issued in a
 * range, each split into the tax, shipping, and merchandise it returned.
 * A refund with lines uses the tax and shipping recorded on them; whatever
 * part of the refund isn't on a line (and a refund with no lines at all) is
 * split in proportion to the order's tax and shipping.
 *
 * Amounts are minor units of the order currency.
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
use ArtisanPackUI\Ecommerce\Models\RefundItem;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class RefundSplit
{
    /**
     * Calls `$each` for every succeeded refund created between `$bounds`,
     * with the refund row (order columns included) and its split.
     *
     * @since 1.0.0
     *
     * @param  array{0: mixed, 1: mixed}                                                          $bounds  Query bounds.
     * @param  Closure(object, array{amount: int, tax: int, shipping: int, merchandise: int}): void  $each    Callback.
     *
     * @return void
     */
    public static function each( array $bounds, Closure $each ): void
    {
        $refunds = ( new Refund() )->getTable();
        $orders  = ( new Order() )->getTable();
        $items   = ( new RefundItem() )->getTable();

        $lines = DB::table( $items )
            ->select( 'refund_id' )
            ->selectRaw( 'COALESCE(SUM(amount), 0) AS line_amount, COALESCE(SUM(tax_amount), 0) AS line_tax, COALESCE(SUM(shipping_amount), 0) AS line_shipping' )
            ->groupBy( 'refund_id' );

        Refund::query()
            ->toBase()
            ->join( $orders, "{$orders}.id", '=', "{$refunds}.order_id" )
            ->leftJoinSub( $lines, 'refund_lines', 'refund_lines.refund_id', '=', "{$refunds}.id" )
            ->select( [
                "{$refunds}.id",
                "{$refunds}.amount",
                "{$refunds}.currency",
                "{$refunds}.created_at",
                "{$refunds}.order_id",
                "{$orders}.base_currency",
                "{$orders}.fx_rate_to_base_e8",
                "{$orders}.tax_amount AS order_tax",
                "{$orders}.shipping_amount AS order_shipping",
                "{$orders}.total_amount AS order_total",
                "{$orders}.shipping_address",
                "{$orders}.billing_address",
                "{$orders}.meta",
                'refund_lines.line_amount',
                'refund_lines.line_tax',
                'refund_lines.line_shipping',
            ] )
            ->whereBetween( "{$refunds}.created_at", $bounds )
            ->where( "{$refunds}.status", Refund::STATUS_SUCCEEDED )
            ->lazyById( 1000, "{$refunds}.id", 'id' )
            ->each( static fn ( object $refund ) => $each( $refund, self::split( $refund ) ) );
    }

    /**
     * Splits one refund row.
     *
     * @since 1.0.0
     *
     * @param  object  $refund  Row from {@see self::each()}.
     *
     * @return array{amount: int, tax: int, shipping: int, merchandise: int}
     */
    public static function split( object $refund ): array
    {
        $amount     = max( 0, (int) $refund->amount );
        $lineAmount = min( $amount, max( 0, (int) ( $refund->line_amount ?? 0 ) ) );
        $rest       = $amount - $lineAmount;
        $total      = max( 0, (int) $refund->order_total );

        $tax      = (int) ( $refund->line_tax ?? 0 ) + self::share( $rest, (int) $refund->order_tax, $total );
        $shipping = (int) ( $refund->line_shipping ?? 0 ) + self::share( $rest, (int) $refund->order_shipping, $total );

        $tax      = min( $tax, $amount );
        $shipping = min( $shipping, $amount - $tax );

        return [
            'amount'      => $amount,
            'tax'         => $tax,
            'shipping'    => $shipping,
            'merchandise' => $amount - $tax - $shipping,
        ];
    }

    /**
     * `$amount × $part ÷ $whole`, rounded half up; 0 without a whole.
     *
     * @since 1.0.0
     *
     * @param  int  $amount  Amount to share.
     * @param  int  $part    Part of the whole.
     * @param  int  $whole   Whole.
     *
     * @return int
     */
    private static function share( int $amount, int $part, int $whole ): int
    {
        if ( $amount <= 0 || $part <= 0 || $whole <= 0 ) {
            return 0;
        }

        return (int) bcdiv( bcadd( bcmul( (string) $amount, (string) $part ), (string) intdiv( $whole, 2 ) ), (string) $whole, 0 );
    }
}
