<?php

/**
 * FulfillmentAllocationStrategyContract test helper.
 *
 * Ships alongside the {@see \ArtisanPackUI\Ecommerce\Contracts\FulfillmentAllocationStrategy}
 * contract so satellite packages can verify their strategy implementations
 * against the same invariants the reference implementation is held to.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing;

use ArtisanPackUI\Ecommerce\Contracts\FulfillmentAllocationStrategy;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use Money\Money;

/**
 * FulfillmentAllocationStrategyContract.
 *
 * Bundle of pure invariant checks a satellite strategy MUST satisfy. Every
 * method returns void and throws (via PHPUnit's assertion helpers) when the
 * invariant fails. Consume from a Pest / PHPUnit test file:
 *
 * ```php
 * use ArtisanPackUI\Ecommerce\Testing\FulfillmentAllocationStrategyContract;
 *
 * it( 'satisfies the FulfillmentAllocationStrategy contract', function (): void {
 *     $contract = new FulfillmentAllocationStrategyContract();
 *     $contract->assertReturnsOneAllocationPerItem( new MyStrategy() );
 *     $contract->assertShippingAllocationsSumToOrderShipping( new MyStrategy() );
 *     $contract->assertTaxAllocationsSumToOrderTax( new MyStrategy() );
 * } );
 * ```
 *
 * The helper deliberately builds `Order` / `OrderItem` instances with plain
 * attribute writes (no database, no factory) so satellite suites can run
 * these checks without booting a Testbench app.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class FulfillmentAllocationStrategyContract
{
    /**
     * Asserts the strategy returns exactly one allocation entry per item,
     * keyed by `OrderItem::id`, with `Money` values for both shipping and tax.
     *
     * @since 1.0.0
     *
     * @param  FulfillmentAllocationStrategy  $strategy  Strategy under test.
     *
     * @return void
     */
    public function assertReturnsOneAllocationPerItem( FulfillmentAllocationStrategy $strategy ): void
    {
        $order  = $this->makeOrder( 10_000, 1_000, 500, 'USD' );
        $items  = [
            $this->makeItem( 1, 1, 4_000, 0, 'USD' ),
            $this->makeItem( 2, 1, 6_000, 0, 'USD' ),
        ];
        $result = $strategy->allocate( $order, $items );

        \PHPUnit\Framework\Assert::assertCount( 2, $result, 'Strategy must return one entry per item.' );
        \PHPUnit\Framework\Assert::assertArrayHasKey( 1, $result );
        \PHPUnit\Framework\Assert::assertArrayHasKey( 2, $result );

        foreach ( $result as $entry ) {
            \PHPUnit\Framework\Assert::assertInstanceOf( Money::class, $entry[ 'shipping' ] );
            \PHPUnit\Framework\Assert::assertInstanceOf( Money::class, $entry[ 'tax' ] );
        }
    }

    /**
     * Asserts the per-item shipping allocations sum exactly to
     * `Order::shipping_amount`, including in the sub-cent-residual case.
     *
     * @since 1.0.0
     *
     * @param  FulfillmentAllocationStrategy  $strategy  Strategy under test.
     *
     * @return void
     */
    public function assertShippingAllocationsSumToOrderShipping( FulfillmentAllocationStrategy $strategy ): void
    {
        $order = $this->makeOrder( 10_000, 1_000, 0, 'USD' );
        $items = [
            $this->makeItem( 1, 1, 3_333, 0, 'USD' ),
            $this->makeItem( 2, 1, 3_333, 0, 'USD' ),
            $this->makeItem( 3, 1, 3_334, 0, 'USD' ),
        ];

        $result = $strategy->allocate( $order, $items );
        $sum    = 0;

        foreach ( $result as $entry ) {
            $sum += (int) $entry[ 'shipping' ]->getAmount();
        }

        \PHPUnit\Framework\Assert::assertSame(
            (int) $order->shipping_amount,
            $sum,
            'Per-item shipping allocations must sum to Order::shipping_amount.',
        );
    }

    /**
     * Asserts the per-item tax allocations sum exactly to `Order::tax_amount`.
     *
     * @since 1.0.0
     *
     * @param  FulfillmentAllocationStrategy  $strategy  Strategy under test.
     *
     * @return void
     */
    public function assertTaxAllocationsSumToOrderTax( FulfillmentAllocationStrategy $strategy ): void
    {
        $order = $this->makeOrder( 10_000, 0, 777, 'USD' );
        $items = [
            $this->makeItem( 1, 1, 3_333, 0, 'USD' ),
            $this->makeItem( 2, 1, 3_333, 0, 'USD' ),
            $this->makeItem( 3, 1, 3_334, 0, 'USD' ),
        ];

        $result = $strategy->allocate( $order, $items );
        $sum    = 0;

        foreach ( $result as $entry ) {
            $sum += (int) $entry[ 'tax' ]->getAmount();
        }

        \PHPUnit\Framework\Assert::assertSame(
            (int) $order->tax_amount,
            $sum,
            'Per-item tax allocations must sum to Order::tax_amount.',
        );
    }

    /**
     * Builds a non-persisted `Order` populated with the given totals.
     *
     * @since 1.0.0
     *
     * @param  int     $subtotal  Order subtotal in minor units.
     * @param  int     $shipping  Order shipping total in minor units.
     * @param  int     $tax       Order tax total in minor units.
     * @param  string  $currency  Three-letter currency code shared by all three totals.
     *
     * @return Order
     */
    private function makeOrder( int $subtotal, int $shipping, int $tax, string $currency ): Order
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
     * Builds a non-persisted `OrderItem` populated with the given values.
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
    private function makeItem( int $id, int $quantity, int $unitPrice, int $discount, string $currency ): OrderItem
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
