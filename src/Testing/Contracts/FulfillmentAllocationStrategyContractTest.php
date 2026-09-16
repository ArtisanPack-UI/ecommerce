<?php

/**
 * FulfillmentAllocationStrategyContractTest.
 *
 * Abstract test class satellite packages extend to prove their
 * {@see \ArtisanPackUI\Ecommerce\Contracts\FulfillmentAllocationStrategy}
 * implementation satisfies the plan §16.7 formula: one allocation per item,
 * shipping allocations sum to `Order::shipping_amount`, tax allocations sum
 * to `Order::tax_amount`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\FulfillmentAllocationStrategy;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * Contract test for {@see FulfillmentAllocationStrategy} implementations.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class FulfillmentAllocationStrategyContractTest extends TestCase
{
    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_returns_one_allocation_per_item_keyed_by_order_item_id(): void
    {
        $order = $this->makeOrder( 10_000, 1_000, 500, 'USD' );
        $items = [
            $this->makeItem( 1, 1, 4_000, 0, 'USD' ),
            $this->makeItem( 2, 1, 6_000, 0, 'USD' ),
        ];

        $result = $this->strategy()->allocate( $order, $items );

        $this->assertCount( 2, $result, 'Strategy must return one entry per item.' );
        $this->assertArrayHasKey( 1, $result );
        $this->assertArrayHasKey( 2, $result );

        foreach ( $result as $entry ) {
            $this->assertInstanceOf( Money::class, $entry[ 'shipping' ] );
            $this->assertInstanceOf( Money::class, $entry[ 'tax' ] );
        }
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_shipping_allocations_sum_to_order_shipping(): void
    {
        $order = $this->makeOrder( 10_000, 1_000, 0, 'USD' );
        $items = [
            $this->makeItem( 1, 1, 3_333, 0, 'USD' ),
            $this->makeItem( 2, 1, 3_333, 0, 'USD' ),
            $this->makeItem( 3, 1, 3_334, 0, 'USD' ),
        ];

        $result = $this->strategy()->allocate( $order, $items );
        $sum    = 0;

        foreach ( $result as $entry ) {
            $sum += (int) $entry[ 'shipping' ]->getAmount();
        }

        $this->assertSame(
            (int) $order->shipping_amount,
            $sum,
            'Per-item shipping allocations must sum to Order::shipping_amount.',
        );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_tax_allocations_sum_to_order_tax(): void
    {
        $order = $this->makeOrder( 10_000, 0, 777, 'USD' );
        $items = [
            $this->makeItem( 1, 1, 3_333, 0, 'USD' ),
            $this->makeItem( 2, 1, 3_333, 0, 'USD' ),
            $this->makeItem( 3, 1, 3_334, 0, 'USD' ),
        ];

        $result = $this->strategy()->allocate( $order, $items );
        $sum    = 0;

        foreach ( $result as $entry ) {
            $sum += (int) $entry[ 'tax' ]->getAmount();
        }

        $this->assertSame(
            (int) $order->tax_amount,
            $sum,
            'Per-item tax allocations must sum to Order::tax_amount.',
        );
    }

    /**
     * Provides the concrete {@see FulfillmentAllocationStrategy} under test.
     *
     * @since 1.0.0
     *
     * @return FulfillmentAllocationStrategy
     */
    abstract protected function strategy(): FulfillmentAllocationStrategy;

    /**
     * Builds a non-persisted {@see Order} populated with the given totals.
     *
     * @since 1.0.0
     *
     * @param  int     $subtotal  Order subtotal in minor units.
     * @param  int     $shipping  Order shipping total in minor units.
     * @param  int     $tax       Order tax total in minor units.
     * @param  string  $currency  Three-letter currency code.
     *
     * @return Order
     */
    protected function makeOrder( int $subtotal, int $shipping, int $tax, string $currency ): Order
    {
        $order = new Order();

        $order->subtotal_amount   = $subtotal;
        $order->subtotal_currency = $currency;
        $order->shipping_amount   = $shipping;
        $order->shipping_currency = $currency;
        $order->tax_amount        = $tax;
        $order->tax_currency      = $currency;

        return $order;
    }

    /**
     * Builds a non-persisted {@see OrderItem} populated with the given values.
     *
     * @since 1.0.0
     *
     * @param  int     $id         Item id (used as the allocation result key).
     * @param  int     $quantity   Quantity ordered.
     * @param  int     $unitPrice  Unit price in minor units.
     * @param  int     $discount   Per-line discount in minor units.
     * @param  string  $currency   Three-letter currency code.
     *
     * @return OrderItem
     */
    protected function makeItem( int $id, int $quantity, int $unitPrice, int $discount, string $currency ): OrderItem
    {
        $item = new OrderItem();

        $item->id                  = $id;
        $item->quantity            = $quantity;
        $item->unit_price_amount   = $unitPrice;
        $item->unit_price_currency = $currency;
        $item->discount_amount     = $discount;
        $item->discount_currency   = $currency;

        return $item;
    }
}
