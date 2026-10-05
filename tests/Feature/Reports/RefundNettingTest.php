<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\RefundItem;
use ArtisanPackUI\Ecommerce\Reports\ReportRange;
use ArtisanPackUI\Ecommerce\Reports\ReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/ReportFixtures.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    configureReportStore();
} );

/**
 * A 100.00 + 8.00 tax + 10.00 shipping order placed on 2026-03-02, with one
 * line and an 8% breakdown.
 */
function nettingOrder(): ArtisanPackUI\Ecommerce\Models\Order
{
    $order = reportOrder( 'USD', [
        'subtotal_amount'  => 10_000,
        'tax_amount'       => 800,
        'shipping_amount'  => 1_000,
        'placed_at'        => Carbon::parse( '2026-03-02 15:00:00' ),
        'shipping_address' => [ 'country_code' => 'US', 'region_code' => 'IL' ],
        'meta'             => [ 'tax_breakdown' => [ [ 'label' => 'Sales tax', 'rate_ubps' => 80_000_000, 'amount' => 800 ] ] ],
    ] );

    reportLine( $order, Product::factory()->create(), 1, 10_000 );

    return $order;
}

function nettingRefund( ArtisanPackUI\Ecommerce\Models\Order $order, int $amount, ?array $line = null ): Refund
{
    $refund = Refund::factory()->create( [ 'order_id' => $order->id, 'amount' => $amount, 'currency' => 'USD', 'status' => 'succeeded', 'created_at' => Carbon::parse( '2026-03-02 18:00:00' ) ] );

    if ( null !== $line ) {
        RefundItem::query()->create( [ 'refund_id' => $refund->id, 'order_item_id' => $order->items()->sole()->id, 'quantity' => 1, 'currency' => 'USD', 'restock' => false ] + $line );
    }

    return $refund;
}

function nettingRange(): ReportRange
{
    return ReportRange::make( '2026-03-01', '2026-03-03', 'day' );
}

it( 'nets a fully refunded order to zero on every metric (D16)', function (): void {
    nettingRefund( nettingOrder(), 11_800, [ 'amount' => 11_800, 'tax_amount' => 800, 'shipping_amount' => 1_000 ] );

    $sales = app( ReportRunner::class )->run( 'sales', nettingRange() )['totals'];
    $tax   = app( ReportRunner::class )->run( 'tax', nettingRange() )['totals'];

    expect( $sales['net'] )->toBe( 0 )
        ->and( $sales['tax'] )->toBe( 0 )
        ->and( $sales['shipping'] )->toBe( 0 )
        ->and( $sales['refunds'] )->toBe( 11_800 )
        ->and( $tax['amount'] )->toBe( 0 );
} );

it( 'takes half the tax off for a half refund, also for a refund without lines', function ( ?array $line ): void {
    nettingRefund( nettingOrder(), 5_900, $line );

    $sales = app( ReportRunner::class )->run( 'sales', nettingRange() )['totals'];
    $tax   = app( ReportRunner::class )->run( 'tax', nettingRange() );

    expect( $sales['tax'] )->toBe( 400 )
        ->and( $sales['net'] )->toBe( 5_000 )
        ->and( $tax['totals']['amount'] )->toBe( 400 )
        ->and( $tax['rows'][0]['jurisdiction'] )->toBe( 'US-IL' );
} )->with( [
    'with a line'    => [ [ 'amount' => 5_900, 'tax_amount' => 400, 'shipping_amount' => 500 ] ],
    'without a line' => [ null ],
] );

it( 'leaves out a refund it can\'t convert instead of counting it as zero', function (): void {
    $order = nettingOrder();
    $order->forceFill( [ 'currency' => 'JPY', 'base_currency' => 'JPY' ] )->save();
    nettingRefund( $order, 11_800 );

    $sales = app( ReportRunner::class )->run( 'sales', nettingRange() )['totals'];

    expect( $sales['refunds'] )->toBe( 0 );
} );
