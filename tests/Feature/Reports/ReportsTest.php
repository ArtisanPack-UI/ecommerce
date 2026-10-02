<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Registries\ReportRegistry;
use ArtisanPackUI\Ecommerce\Reports\Report;
use ArtisanPackUI\Ecommerce\Reports\ReportRange;
use ArtisanPackUI\Ecommerce\Reports\ReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/ReportFixtures.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    configureReportStore();
} );

function runReport( string $key, ?ReportRange $range = null, array $options = [] ): array
{
    return app( ReportRunner::class )->run( $key, $range, $options );
}

function march( string $interval = 'day', bool $compare = false ): ReportRange
{
    return ReportRange::make( '2026-03-01', '2026-03-03', $interval, $compare );
}

it( 'registers the core reports in order', function (): void {
    expect( app( ReportRegistry::class )->ordered() )->toBe( [ 'sales', 'top-products', 'revenue-by-category', 'tax', 'inventory', 'low-stock', 'summary' ] )
        ->and( collect( app( ReportRunner::class )->available() )->firstWhere( 'key', 'inventory' )['ranged'] )->toBeFalse();
} );

it( 'totals sales per store-time-zone day, converting each order with its snapshot rate', function (): void {
    seedReportFixtures();

    $report = runReport( 'sales', march() );

    expect( $report['currency'] )->toBe( 'USD' )
        ->and( $report['timezone'] )->toBe( 'America/New_York' )
        ->and( $report['range'] )->toMatchArray( [ 'from' => '2026-03-01', 'to' => '2026-03-03', 'interval' => 'day' ] )
        ->and( array_column( $report['series'], 'period' ) )->toBe( [ '2026-03-01', '2026-03-02', '2026-03-03' ] )
        // Order B was placed at 03:00 UTC on the 2nd — the evening of the 1st in New York.
        ->and( $report['series'][0] )->toMatchArray( [ 'gross' => 15_500, 'discounts' => 1_000, 'refunds' => 0, 'net' => 14_500, 'tax' => 900, 'shipping' => 500, 'total' => 15_900, 'orders' => 2, 'average_order_value' => 7_950 ] )
        ->and( $report['series'][1] )->toMatchArray( [ 'gross' => 3_000, 'total' => 3_000, 'orders' => 1 ] )
        ->and( $report['series'][2] )->toMatchArray( [ 'gross' => 10_050, 'refunds' => 2_000, 'net' => 8_050, 'tax' => 1_005, 'total' => 11_055, 'orders' => 1 ] )
        ->and( $report['totals'] )->toBe( [
            'gross'               => 28_550,
            'discounts'           => 1_000,
            'refunds'             => 2_000,
            'net'                 => 25_550,
            'tax'                 => 1_905,
            'shipping'            => 500,
            'total'               => 29_955,
            'orders'              => 4,
            'average_order_value' => 7_489,
        ] )
        ->and( $report['notices'] )->toBe( [ 'converted_orders' => 1, 'unconverted_orders' => 0 ] )
        ->and( $report['previous'] )->toBeNull();
} );

it( 'compares with the previous period of the same length', function (): void {
    seedReportFixtures();

    $report = runReport( 'sales', march( 'day', true ) );

    expect( $report['previous']['range'] )->toMatchArray( [ 'from' => '2026-02-26', 'to' => '2026-02-28' ] )
        ->and( $report['previous']['totals'] )->toMatchArray( [ 'orders' => 0, 'refunds' => 1_100, 'net' => -1_100 ] );
} );

it( 'buckets by ISO week and by month', function (): void {
    seedReportFixtures();

    $weeks  = runReport( 'sales', march( 'week' ) );
    $months = runReport( 'sales', ReportRange::make( '2026-02-01', '2026-03-31', 'month' ) );

    expect( array_column( $weeks['series'], 'period' ) )->toBe( [ '2026-02-23', '2026-03-02' ] )
        ->and( array_column( $weeks['series'], 'orders' ) )->toBe( [ 2, 2 ] )
        ->and( array_column( $months['series'], 'period' ) )->toBe( [ '2026-02', '2026-03' ] )
        ->and( $months['series'][0]['refunds'] )->toBe( 1_100 )
        ->and( $months['series'][1]['total'] )->toBe( 29_955 );
} );

it( 'leaves out and counts orders whose old base currency has no cross-rate', function (): void {
    reportOrder( 'CHF', [ 'placed_at' => '2026-03-02 15:00:00', 'base_currency' => 'CHF', 'subtotal_amount' => 1_000 ] );
    reportOrder( 'USD', [ 'placed_at' => '2026-03-02 15:00:00', 'subtotal_amount' => 700 ] );

    $report = runReport( 'sales', march() );

    expect( $report['totals']['gross'] )->toBe( 700 )
        ->and( $report['totals']['orders'] )->toBe( 1 )
        ->and( $report['totals']['average_order_value'] )->toBe( 700 )
        ->and( $report['notices'] )->toBe( [ 'converted_orders' => 0, 'unconverted_orders' => 1 ] );
} );

it( 'keeps historical revenue fixed when the base currency changes', function (): void {
    seedReportFixtures();

    $before = runReport( 'sales', march() )['totals']['total'];

    // New orders adopt GBP as their base; old ones keep their USD snapshot
    // and are converted to GBP at today's rate (1 GBP = 1.25 USD).
    config()->set( 'artisanpack.ecommerce.base_currency', 'GBP' );

    $after = runReport( 'sales', march() );

    expect( $before )->toBe( 29_955 )
        ->and( $after['currency'] )->toBe( 'GBP' )
        // A + B + E are converted at 1/1.25; C was placed under GBP and is no longer flagged.
        ->and( $after['totals']['total'] )->toBe( 8_320 + 4_400 + 2_400 + 8_844 )
        ->and( $after['notices']['converted_orders'] )->toBe( 3 );
} );

it( 'ranks top products by net revenue or units, with variants', function (): void {
    seedReportFixtures();

    $report = runReport( 'top-products', march() );

    expect( array_column( $report['rows'], 'name' ) )->toBe( [ 'Mug', 'Tee', 'Pin' ] )
        ->and( $report['rows'][0] )->toMatchArray( [ 'units' => 4, 'net_revenue' => 15_550, 'orders' => 2 ] )
        ->and( $report['rows'][0]['variants'] )->toBe( [
            [ 'variant_id' => $report['rows'][0]['variants'][0]['variant_id'], 'name' => 'Green', 'sku' => 'MUG-G', 'units' => 3, 'net_revenue' => 10_050 ],
            [ 'variant_id' => $report['rows'][0]['variants'][1]['variant_id'], 'name' => 'Blue', 'sku' => 'MUG-B', 'units' => 1, 'net_revenue' => 5_500 ],
        ] )
        // Tee: 2 × 50.00 − 10.00 discount − 20.00 refunded (1 unit), plus 20.00 GBP at 1.25.
        ->and( $report['rows'][1] )->toMatchArray( [ 'units' => 2, 'net_revenue' => 9_500 ] )
        ->and( $report['totals'] )->toBe( [ 'products' => 3, 'units' => 7, 'net_revenue' => 25_550, 'orders' => 4 ] );

    $byUnits = runReport( 'top-products', march(), [ 'sort' => 'units', 'limit' => 2 ] );

    expect( array_column( $byUnits['rows'], 'name' ) )->toBe( [ 'Mug', 'Tee' ] )
        ->and( $byUnits['sort'] )->toBe( 'units' )
        ->and( $byUnits['totals']['products'] )->toBe( 3 );
} );

it( 'names deleted products from the line snapshot', function (): void {
    $fixtures = seedReportFixtures();
    $fixtures['pin']->delete();

    $rows = runReport( 'top-products', march() )['rows'];

    expect( collect( $rows )->firstWhere( 'product_id', null )['name'] )->toBe( 'Pin' );
} );

it( 'splits net revenue by category, counting a product in each of its categories', function (): void {
    seedReportFixtures();

    $report = runReport( 'revenue-by-category', march() );

    expect( array_column( $report['rows'], 'name' ) )->toBe( [ 'Shirts', 'Mugs', 'Uncategorized' ] )
        ->and( array_column( $report['rows'], 'net_revenue' ) )->toBe( [ 25_050, 15_550, 500 ] )
        ->and( array_column( $report['rows'], 'units' ) )->toBe( [ 6, 4, 1 ] )
        ->and( $report['rows'][2]['category_id'] )->toBeNull()
        ->and( $report['rows'][0]['share'] )->toBe( 0.9804 )
        ->and( $report['totals'] )->toBe( [ 'units' => 7, 'net_revenue' => 25_550, 'orders' => 4, 'categories' => 3 ] );
} );

it( 'totals tax by rate label and jurisdiction', function (): void {
    seedReportFixtures();

    $report = runReport( 'tax', march() );

    expect( array_map( static fn ( array $row ): array => [ $row['jurisdiction'], $row['label'], $row['amount'] ], $report['rows'] ) )->toBe( [
        [ 'JP-13', 'Tax', 1_005 ],
        [ 'US-NY', 'City tax', 300 ],
        [ 'US-NY', 'State tax', 600 ],
    ] )
        ->and( $report['rows'][2]['rate_ubps'] )->toBe( 400_000 )
        ->and( $report['rows'][2]['country_code'] )->toBe( 'US' )
        ->and( $report['totals'] )->toBe( [ 'amount' => 1_905, 'orders' => 2 ] );
} );

it( 'reports inventory levels and stock value at cost', function (): void {
    seedReportFixtures();

    $report = runReport( 'inventory' );

    expect( $report['range'] )->toBeNull()
        ->and( $report['previous'] )->toBeNull()
        ->and( array_column( $report['rows'], 'name' ) )->toBe( [ 'Tee', 'Mug — Blue', 'Pin' ] )
        ->and( $report['rows'][0] )->toMatchArray( [ 'stockable_type' => 'product', 'on_hand' => 10, 'reserved' => 2, 'available' => 8, 'unit_cost' => 1_500, 'stock_value' => 15_000 ] )
        // A variant without its own price uses its product's cost.
        ->and( $report['rows'][1] )->toMatchArray( [ 'stockable_type' => 'variant', 'available' => 2, 'unit_cost' => 800, 'stock_value' => 2_400 ] )
        // Oversold, and only a EUR cost: no base-currency value.
        ->and( $report['rows'][2] )->toMatchArray( [ 'on_hand' => -2, 'available' => -2, 'unit_cost' => null, 'stock_value' => null ] )
        ->and( $report['totals'] )->toBe( [ 'items' => 3, 'on_hand' => 11, 'reserved' => 3, 'available' => 8, 'stock_value' => 17_400, 'items_without_cost' => 1 ] );

    expect( array_column( runReport( 'inventory', null, [ 'sort' => 'name', 'limit' => 2 ] )['rows'], 'name' ) )->toBe( [ 'Mug — Blue', 'Pin' ] );
} );

it( 'lists tracked items at or below their threshold', function (): void {
    seedReportFixtures();

    $report = runReport( 'low-stock' );

    expect( $report['totals'] )->toBe( [ 'items' => 1 ] )
        ->and( $report['rows'][0] )->toMatchArray( [ 'name' => 'Mug — Blue', 'available' => 2, 'low_stock_threshold' => 5, 'shortfall' => 3 ] );
} );

it( 'summarizes the dashboard KPIs from the same numbers', function (): void {
    seedReportFixtures();
    ProductReview::factory()->create();
    ProductReview::factory()->create( [ 'status' => ProductReview::STATUS_APPROVED ] );

    $this->travelTo( Illuminate\Support\Carbon::parse( '2026-03-03 18:00:00', 'UTC' ) );

    $summary = runReport( 'summary' );

    expect( $summary['totals'] )->toBe( [
        'sales_today'          => 11_055,
        'net_sales_today'      => 8_050,
        'orders_today'         => 1,
        'sales_30_days'        => 29_955,
        'net_sales_30_days'    => 24_450,
        'orders_30_days'       => 4,
        'awaiting_fulfillment' => 1,
        'low_stock'            => 1,
        'pending_reviews'      => 1,
    ] );
} );

it( 'lets satellites register reports and filter results', function (): void {
    $report = new class extends Report {
        public function run( ?ReportRange $range, array $options = [] ): array
        {
            return $this->result( $range, $this->amounts(), [ 'rows' => [ [ 'cohort' => '2026-03' ] ] ] );
        }
    };

    app( ReportRegistry::class )->register( 'cohorts', $report, [ 'label' => 'Cohorts' ] );
    addFilter( 'ap.ecommerce.reports.result', static fn ( array $result, string $key ): array => 'cohorts' === $key ? [ ...$result, 'extra' => true ] : $result );

    expect( runReport( 'cohorts', march() ) )->toMatchArray( [ 'report' => 'cohorts', 'label' => 'Cohorts', 'rows' => [ [ 'cohort' => '2026-03' ] ], 'extra' => true ] );
} );

it( 'refuses unknown reports', function (): void {
    runReport( 'nope' );
} )->throws( InvalidArgumentException::class );

it( 'only counts sale payment statuses', function (): void {
    reportOrder( 'USD', [ 'placed_at' => '2026-03-02 15:00:00', 'payment_status' => 'failed', 'subtotal_amount' => 1_000 ] );
    reportOrder( 'USD', [ 'placed_at' => '2026-03-02 15:00:00', 'payment_status' => 'refunded', 'subtotal_amount' => 400 ] );
    reportOrder( 'USD', [ 'placed_at' => '2026-03-02 15:00:00', 'payment_status' => 'partially_refunded', 'subtotal_amount' => 300 ] );

    expect( runReport( 'sales', march() )['totals']['gross'] )->toBe( 700 )
        ->and( Order::query()->count() )->toBe( 3 );
} );

it( 'caps a line\'s refunded amount at its pre-tax value', function (): void {
    $product = ArtisanPackUI\Ecommerce\Models\Product::factory()->create( [ 'name' => 'Lamp' ] );
    $order   = reportOrder( 'USD', [ 'placed_at' => '2026-03-02 15:00:00', 'subtotal_amount' => 1_000, 'tax_amount' => 100, 'shipping_amount' => 200 ] );
    $line    = reportLine( $order, $product, 1, 1_000 );
    $refund  = ArtisanPackUI\Ecommerce\Models\Refund::factory()->create( [ 'order_id' => $order->id, 'amount' => 1_300, 'currency' => 'USD' ] );
    ArtisanPackUI\Ecommerce\Models\RefundItem::factory()->create( [ 'refund_id' => $refund->id, 'order_item_id' => $line->id, 'quantity' => 1, 'amount' => 1_300, 'currency' => 'USD' ] );

    expect( runReport( 'top-products', march() )['rows'][0] )->toMatchArray( [ 'units' => 0, 'net_revenue' => 0 ] );
} );

it( 'ignores a tax breakdown that does not add up to the order\'s tax', function (): void {
    reportOrder( 'USD', [
        'placed_at'        => '2026-03-02 15:00:00',
        'subtotal_amount'  => 1_000,
        'tax_amount'       => 80,
        'shipping_address' => [ 'country_code' => 'US', 'region_code' => 'TX', 'city' => 'Austin', 'address1' => '1 Main' ],
        'meta'             => [ 'tax_breakdown' => [ [ 'label' => 'Forged', 'amount' => 999_999 ] ] ],
    ] );

    $report = runReport( 'tax', march() );

    expect( $report['rows'] )->toHaveCount( 1 )
        ->and( $report['rows'][0] )->toMatchArray( [ 'label' => 'Tax', 'jurisdiction' => 'US-TX', 'amount' => 80 ] );
} );

it( 'finds low stock when more is reserved than on hand', function (): void {
    $product = ArtisanPackUI\Ecommerce\Models\Product::factory()->create();
    ArtisanPackUI\Ecommerce\Models\InventoryItem::factory()->create( [ 'stockable_type' => $product->getMorphClass(), 'stockable_id' => $product->id, 'quantity_on_hand' => 1, 'quantity_reserved' => 4, 'low_stock_threshold' => 2 ] );

    expect( runReport( 'low-stock' )['rows'][0] )->toMatchArray( [ 'available' => -3, 'shortfall' => 5 ] );
} );
