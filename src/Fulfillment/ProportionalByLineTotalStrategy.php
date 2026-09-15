<?php

/**
 * ProportionalByLineTotalStrategy.
 *
 * Reference {@see \ArtisanPackUI\Ecommerce\Contracts\FulfillmentAllocationStrategy}
 * implementation, matching parent plan §16.7 exactly.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Fulfillment;

use ArtisanPackUI\Ecommerce\Contracts\FulfillmentAllocationStrategy;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use Money\Currency;
use Money\Money;

/**
 * ProportionalByLineTotalStrategy.
 *
 * Splits `Order::shipping_amount` and `Order::tax_amount` across the given
 * line items in proportion to each item's line total, where a line total is
 * defined per parent plan §16.7 as:
 *
 *     line_total(item) = item.quantity * item.unit_price_amount - item.discount_amount
 *
 * Given that definition, for each item:
 *
 *     allocated_shipping(item) = order.shipping × ( line_total(item) / order.subtotal )
 *     allocated_tax(item)      = order.tax      × ( line_total(item) / order.subtotal )
 *
 * Per-item results use banker's rounding (`PHP_ROUND_HALF_EVEN`). Any
 * sub-cent residual left over from the rounded terms is pushed onto the
 * last item so summed per-item values still exactly equal the order totals.
 *
 * If `order.subtotal_amount` is zero (or the summed line totals are zero,
 * e.g. every line is a 100%-off promo) shipping and tax split equally with
 * the residual on the last item — the formula would divide by zero
 * otherwise, and refunds would break.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ProportionalByLineTotalStrategy implements FulfillmentAllocationStrategy
{
    /**
     * Registry key.
     *
     * @since 1.0.0
     */
    public const KEY = 'proportional-by-line-total';

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * Allocates `$order`'s shipping and tax across `$items` per §16.7.
     *
     * @since 1.0.0
     *
     * @param  Order                $order  Order whose totals are being split.
     * @param  iterable<OrderItem>  $items  Items to allocate across.
     *
     * @return array<int, array{shipping: Money, tax: Money}>
     */
    public function allocate( Order $order, iterable $items ): array
    {
        $list = [];

        foreach ( $items as $item ) {
            $list[] = $item;
        }

        if ( [] === $list ) {
            return [];
        }

        $shippingCurrency = new Currency( $order->shipping_currency );
        $taxCurrency      = new Currency( $order->tax_currency );

        $orderShipping = (int) $order->shipping_amount;
        $orderTax      = (int) $order->tax_amount;

        $lineTotals = [];
        $totalLines = 0;

        foreach ( $list as $item ) {
            $lineTotal            = ( (int) $item->quantity * (int) $item->unit_price_amount ) - (int) $item->discount_amount;
            $lineTotals[]         = $lineTotal;
            $totalLines += $lineTotal;
        }

        $divisor = 0 !== (int) $order->subtotal_amount ? (int) $order->subtotal_amount : $totalLines;

        $shippingAllocations = $this->distribute( $orderShipping, $lineTotals, $divisor );
        $taxAllocations      = $this->distribute( $orderTax, $lineTotals, $divisor );

        $out = [];

        foreach ( $list as $index => $item ) {
            $out[ (int) $item->id ] = [
                'shipping' => new Money( $shippingAllocations[ $index ], $shippingCurrency ),
                'tax'      => new Money( $taxAllocations[ $index ], $taxCurrency ),
            ];
        }

        return $out;
    }

    /**
     * Splits `$total` across the positions in `$lineTotals` in proportion to
     * each entry, using banker's rounding and dropping the sub-cent residual
     * onto the last position. Falls back to an even split when `$divisor` is
     * zero.
     *
     * @since 1.0.0
     *
     * @param  int              $total       Amount to split (in minor units).
     * @param  array<int, int>  $lineTotals  Per-item line totals used as weights.
     * @param  int              $divisor     Denominator (usually the order subtotal).
     *
     * @return array<int, int>
     */
    private function distribute( int $total, array $lineTotals, int $divisor ): array
    {
        $count = count( $lineTotals );

        if ( 0 === $count ) {
            return [];
        }

        $allocations = [];
        $running     = 0;

        if ( 0 === $divisor ) {
            $even    = intdiv( $total, $count );
            $running = $even * $count;

            for ( $i = 0; $i < $count; $i++ ) {
                $allocations[] = $even;
            }
        } else {
            foreach ( $lineTotals as $lineTotal ) {
                $portion       = (int) round( ( $total * $lineTotal ) / $divisor, 0, PHP_ROUND_HALF_EVEN );
                $allocations[] = $portion;
                $running += $portion;
            }
        }

        $allocations[ $count - 1 ] += ( $total - $running );

        return $allocations;
    }
}
