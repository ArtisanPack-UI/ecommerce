<?php

/**
 * LineItemReport.
 *
 * Base for reports built from sold order lines (top products, revenue by
 * category). A line counts when its order was placed in the range with a
 * sale payment status. Each line is reported net of the refunds issued
 * against it so far:
 *
 * - `units` = quantity − refunded quantity
 * - `net`   = unit price × quantity − line discount − refunded amount
 *             (capped at that pre-tax value, since a refund can include the
 *             line's tax and shipping), converted to the base currency
 *             (see {@see BaseAmounts})
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
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\RefundItem;
use Closure;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class LineItemReport extends Report
{
    /**
     * Calls `$callback` once per sold line in the range.
     *
     * @since 1.0.0
     *
     * @param  ReportRange                                                                                             $range     Range.
     * @param  BaseAmounts                                                                                             $amounts   Converter.
     * @param  Closure(array{order_id: int, product_id: int|null, variant_id: int|null, snapshot: array<string, mixed>, units: int, net: int}): void  $callback  Receives each line.
     *
     * @return void
     */
    protected function eachSoldLine( ReportRange $range, BaseAmounts $amounts, Closure $callback ): void
    {
        $items       = ( new OrderItem() )->getTable();
        $orders      = ( new Order() )->getTable();
        $refundItems = ( new RefundItem() )->getTable();
        $refundRows  = ( new Refund() )->getTable();

        $soldLines = static fn () => OrderItem::query()
            ->toBase()
            ->join( $orders, "{$orders}.id", '=', "{$items}.order_id" )
            ->whereNotNull( "{$orders}.placed_at" )
            ->whereBetween( "{$orders}.placed_at", $range->queryBounds() )
            ->whereIn( "{$orders}.payment_status", self::SALE_PAYMENT_STATUSES );

        // Refunded units and merchandise per line, for the lines in range
        // only, read once (not once per chunk of lines).
        $refunded = RefundItem::query()
            ->toBase()
            ->join( $refundRows, "{$refundRows}.id", '=', "{$refundItems}.refund_id" )
            ->where( "{$refundRows}.status", Refund::STATUS_SUCCEEDED )
            ->whereIn( "{$refundItems}.order_item_id", $soldLines()->select( "{$items}.id" ) )
            ->select( "{$refundItems}.order_item_id" )
            ->selectRaw( "SUM({$refundItems}.quantity) as refunded_quantity" )
            ->selectRaw( "SUM({$refundItems}.amount - COALESCE({$refundItems}.tax_amount, 0) - COALESCE({$refundItems}.shipping_amount, 0)) as refunded_amount" )
            ->groupBy( "{$refundItems}.order_item_id" )
            ->get()
            ->keyBy( 'order_item_id' );

        $soldLines()
            ->select( [
                "{$items}.id",
                "{$items}.order_id",
                "{$items}.product_id",
                "{$items}.product_variant_id",
                "{$items}.product_snapshot",
                "{$items}.quantity",
                "{$items}.unit_price_amount",
                "{$items}.unit_price_currency",
                "{$items}.discount_amount",
                "{$orders}.base_currency",
                "{$orders}.fx_rate_to_base_e8",
            ] )
            ->lazyById( 1000, "{$items}.id", 'id' )
            ->each( static function ( object $line ) use ( $amounts, $callback, $refunded ): void {
                $refund = $refunded->get( $line->id );

                // The merchandise part of the line's refunds, capped at the
                // line's pre-tax value.
                $lineValue = (int) $line->unit_price_amount * (int) $line->quantity - (int) $line->discount_amount;
                $gross     = $lineValue - min( max( 0, $lineValue ), max( 0, (int) ( $refund->refunded_amount ?? 0 ) ) );
                $net       = $amounts->toBase( $gross, (string) $line->unit_price_currency, (string) $line->base_currency, $line->fx_rate_to_base_e8, $line->order_id );

                if ( null === $net ) {
                    return;
                }

                $snapshot = is_string( $line->product_snapshot ) ? json_decode( $line->product_snapshot, true ) : $line->product_snapshot;

                $callback( [
                    'order_id'   => (int) $line->order_id,
                    'product_id' => null === $line->product_id ? null : (int) $line->product_id,
                    'variant_id' => null === $line->product_variant_id ? null : (int) $line->product_variant_id,
                    'snapshot'   => is_array( $snapshot ) ? $snapshot : [],
                    'units'      => max( 0, (int) $line->quantity - (int) ( $refund->refunded_quantity ?? 0 ) ),
                    'net'        => $net,
                ] );
            } );
    }
}
