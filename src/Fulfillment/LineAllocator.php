<?php

/**
 * LineAllocator.
 *
 * Writes an order's per-line shipping, tax, and total: each line keeps the
 * tax calculated for it, and the order's shipping plus whatever tax isn't
 * tied to a line (tax on shipping) are split across the lines by the
 * configured {@see FulfillmentAllocationStrategy}. A line's total is
 * `unit × qty − discount + shipping + tax`, without the tax when prices
 * already include it. Used at placement and after an order edit, so both
 * split amounts the same way.
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
use ArtisanPackUI\Ecommerce\Registries\FulfillmentAllocationStrategyRegistry;
use Illuminate\Support\Collection;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class LineAllocator
{
    /**
     * @since 1.0.0
     *
     * @param  FulfillmentAllocationStrategyRegistry  $strategies  Allocation strategies.
     */
    public function __construct(
        protected FulfillmentAllocationStrategyRegistry $strategies,
    ) {
    }

    /**
     * Allocates and saves the lines of `$order`.
     *
     * @since 1.0.0
     *
     * @param  Order                       $order      Order (its shipping and tax totals are split).
     * @param  Collection<int, OrderItem>  $items      Its lines.
     * @param  array<int, int>             $lineTax    Tax calculated for each line, by line id.
     * @param  bool                        $inclusive  Whether prices include tax.
     *
     * @return void
     */
    public function allocate( Order $order, Collection $items, array $lineTax, bool $inclusive ): void
    {
        if ( $items->isEmpty() ) {
            return;
        }

        $items = $items->sortBy( 'id' )->values();

        // The strategy splits whatever is on the order it's handed: here the
        // shipping and the tax left over once each line's own tax is counted.
        $probe             = $order->replicate();
        $probe->tax_amount = max( 0, (int) $order->tax_amount - array_sum( array_map( 'intval', $lineTax ) ) );

        $shares = $this->strategy()->allocate( $probe, $items );

        foreach ( $items as $item ) {
            $share    = $shares[ (int) $item->id ] ?? null;
            $shipping = null === $share ? 0 : (int) $share['shipping']->getAmount();
            $tax      = (int) ( $lineTax[ (int) $item->id ] ?? 0 ) + ( null === $share ? 0 : (int) $share['tax']->getAmount() );

            $item->forceFill( [
                'shipping_amount' => $shipping,
                'tax_amount'      => $tax,
                'total_amount'    => (int) $item->unit_price_amount * (int) $item->quantity - (int) $item->discount_amount + $shipping + ( $inclusive ? 0 : $tax ),
            ] )->save();
        }
    }

    /**
     * The configured strategy.
     *
     * @since 1.0.0
     *
     * @return FulfillmentAllocationStrategy
     */
    protected function strategy(): FulfillmentAllocationStrategy
    {
        return $this->strategies->active();
    }
}
