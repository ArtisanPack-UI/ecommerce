<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Fulfillment\ProportionalByLineTotalStrategy;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Testing\FulfillmentAllocationStrategyContract;
use Money\Money;

if ( ! function_exists( 'makeAllocationOrder' ) ) {
    /**
     * Builds a non-persisted Order populated with the given totals.
     */
    function makeAllocationOrder( int $subtotal, int $shipping, int $tax, string $currency = 'USD' ): Order
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
}

if ( ! function_exists( 'makeAllocationItem' ) ) {
    /**
     * Builds a non-persisted OrderItem.
     */
    function makeAllocationItem( int $id, int $quantity, int $unitPrice, int $discount = 0, string $currency = 'USD' ): OrderItem
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

it( 'returns an empty array when no items are passed', function (): void {
    $strategy = new ProportionalByLineTotalStrategy();
    $order    = makeAllocationOrder( 0, 1_000, 500 );

    expect( $strategy->allocate( $order, [] ) )->toBe( [] );
} );

it( 'reports its registry key', function (): void {
    expect( ( new ProportionalByLineTotalStrategy() )->key() )->toBe( 'proportional-by-line-total' );
} );

it( 'splits shipping and tax proportionally to each line total', function (): void {
    $strategy = new ProportionalByLineTotalStrategy();
    $order    = makeAllocationOrder( 10_000, 1_000, 500 );
    $items    = [
        makeAllocationItem( 1, 1, 4_000 ),
        makeAllocationItem( 2, 1, 6_000 ),
    ];

    $result = $strategy->allocate( $order, $items );

    expect( (int) $result[ 1 ][ 'shipping' ]->getAmount() )->toBe( 400 )
        ->and( (int) $result[ 2 ][ 'shipping' ]->getAmount() )->toBe( 600 )
        ->and( (int) $result[ 1 ][ 'tax' ]->getAmount() )->toBe( 200 )
        ->and( (int) $result[ 2 ][ 'tax' ]->getAmount() )->toBe( 300 );
} );

it( 'uses banker\'s rounding on half values', function (): void {
    // Two items at 50/50: 1 cent shipping rounds under banker's rules to 0 + 0
    // (half rounds to even), leaving the whole 1 cent as residual to the last.
    $strategy = new ProportionalByLineTotalStrategy();
    $order    = makeAllocationOrder( 200, 1, 0 );
    $items    = [
        makeAllocationItem( 1, 1, 100 ),
        makeAllocationItem( 2, 1, 100 ),
    ];

    $result = $strategy->allocate( $order, $items );

    // 1 * 100 / 200 = 0.5 -> banker's rounding to nearest even = 0
    // 1 * 100 / 200 = 0.5 -> banker's rounding to nearest even = 0
    // Residual 1 lands on the last item.
    expect( (int) $result[ 1 ][ 'shipping' ]->getAmount() )->toBe( 0 )
        ->and( (int) $result[ 2 ][ 'shipping' ]->getAmount() )->toBe( 1 );
} );

it( 'pushes the sub-cent residual onto the last item', function (): void {
    // 10 cents shipping split across three equal-weight lines: 3.33 → 3, 3, 4.
    $strategy = new ProportionalByLineTotalStrategy();
    $order    = makeAllocationOrder( 9_999, 10, 0 );
    $items    = [
        makeAllocationItem( 1, 1, 3_333 ),
        makeAllocationItem( 2, 1, 3_333 ),
        makeAllocationItem( 3, 1, 3_333 ),
    ];

    $result = $strategy->allocate( $order, $items );

    $sum = (int) $result[ 1 ][ 'shipping' ]->getAmount()
        + (int) $result[ 2 ][ 'shipping' ]->getAmount()
        + (int) $result[ 3 ][ 'shipping' ]->getAmount();

    expect( $sum )->toBe( 10 )
        ->and( (int) $result[ 3 ][ 'shipping' ]->getAmount() )
        ->toBeGreaterThanOrEqual( (int) $result[ 1 ][ 'shipping' ]->getAmount() );
} );

it( 'summed shipping equals order shipping across a wide range of splits', function ( int $shipping, array $lineTotals ): void {
    $strategy = new ProportionalByLineTotalStrategy();
    $subtotal = array_sum( $lineTotals );
    $order    = makeAllocationOrder( $subtotal, $shipping, 0 );
    $items    = [];

    foreach ( $lineTotals as $index => $lineTotal ) {
        $items[] = makeAllocationItem( $index + 1, 1, $lineTotal );
    }

    $result = $strategy->allocate( $order, $items );
    $sum    = 0;

    foreach ( $result as $entry ) {
        $sum += (int) $entry[ 'shipping' ]->getAmount();
    }

    expect( $sum )->toBe( $shipping );
} )->with( [
    'three equal thirds'            => [ 100, [ 33, 33, 34 ] ],
    'one dominant line'             => [ 999, [ 9_000, 500, 500 ] ],
    'sub-cent residual on last'     => [ 7, [ 100, 100, 100 ] ],
    'seven-way tiny split'          => [ 13, [ 100, 100, 100, 100, 100, 100, 100 ] ],
    'wide zero-shipping'            => [ 0, [ 100, 200, 300 ] ],
] );

it( 'accounts for per-line discounts when computing weights', function (): void {
    $strategy = new ProportionalByLineTotalStrategy();
    // subtotal = (1 * 8_000 - 3_000) + (1 * 6_000 - 1_000) = 5_000 + 5_000 = 10_000
    $order = makeAllocationOrder( 10_000, 1_000, 0 );
    $items = [
        makeAllocationItem( 1, 1, 8_000, 3_000 ),
        makeAllocationItem( 2, 1, 6_000, 1_000 ),
    ];

    $result = $strategy->allocate( $order, $items );

    expect( (int) $result[ 1 ][ 'shipping' ]->getAmount() )->toBe( 500 )
        ->and( (int) $result[ 2 ][ 'shipping' ]->getAmount() )->toBe( 500 );
} );

it( 'splits shipping equally when the subtotal is zero', function (): void {
    $strategy = new ProportionalByLineTotalStrategy();
    // All-free order (100% promo): fall back to an even split so refunds still work.
    $order = makeAllocationOrder( 0, 300, 0 );
    $items = [
        makeAllocationItem( 1, 1, 0 ),
        makeAllocationItem( 2, 1, 0 ),
        makeAllocationItem( 3, 1, 0 ),
    ];

    $result = $strategy->allocate( $order, $items );

    expect( (int) $result[ 1 ][ 'shipping' ]->getAmount() )->toBe( 100 )
        ->and( (int) $result[ 2 ][ 'shipping' ]->getAmount() )->toBe( 100 )
        ->and( (int) $result[ 3 ][ 'shipping' ]->getAmount() )->toBe( 100 );
} );

it( 'returns Money values tagged with the order\'s shipping and tax currencies', function (): void {
    $strategy = new ProportionalByLineTotalStrategy();
    $order    = makeAllocationOrder( 10_000, 500, 200 );
    // Mixed currencies on the order — shipping in EUR, tax in GBP.
    $order->shipping_currency = 'EUR';
    $order->tax_currency      = 'GBP';
    $items                    = [
        makeAllocationItem( 1, 1, 10_000 ),
    ];

    $result = $strategy->allocate( $order, $items );

    expect( $result[ 1 ][ 'shipping' ] )->toBeInstanceOf( Money::class )
        ->and( $result[ 1 ][ 'shipping' ]->getCurrency()->getCode() )->toBe( 'EUR' )
        ->and( $result[ 1 ][ 'tax' ]->getCurrency()->getCode() )->toBe( 'GBP' );
} );

it( 'satisfies the shared FulfillmentAllocationStrategy contract', function (): void {
    $strategy = new ProportionalByLineTotalStrategy();
    $contract = new FulfillmentAllocationStrategyContract();

    $contract->assertReturnsOneAllocationPerItem( $strategy );
    $contract->assertShippingAllocationsSumToOrderShipping( $strategy );
    $contract->assertTaxAllocationsSumToOrderTax( $strategy );
} );
