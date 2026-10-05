<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\TaxRate;
use ArtisanPackUI\Ecommerce\Services\OrderEditService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

/**
 * A pending, taxed order: one 100.00 line at 10% tax, shipped to Chicago.
 */
function taxedOrder( array $attributes = [] ): Order
{
    TaxRate::factory()->create( [ 'rate_ubps' => 100_000_000 ] );

    $order = Order::factory()->create( $attributes + [
        'system_status'    => 'pending',
        'subtotal_amount'  => 10_000,
        'tax_amount'       => 1_000,
        'total_amount'     => 11_000,
        'shipping_address' => [ 'address1' => '1 Main St', 'city' => 'Chicago', 'country_code' => 'US', 'region_code' => 'IL', 'postal_code' => '60601' ],
    ] );

    OrderItem::factory()->create( [
        'order_id'          => $order->id,
        'product_id'        => Product::factory()->create()->id,
        'quantity'          => 1,
        'unit_price_amount' => 10_000,
        'tax_amount'        => 1_000,
        'total_amount'      => 11_000,
    ] );

    return $order->refresh();
}

it( 'recalculates tax when a line changes (D8)', function (): void {
    $order = taxedOrder();
    $item  = $order->items()->sole();

    app( OrderEditService::class )->apply( $order, [ 'items' => [ 'change' => [ $item->id => [ 'quantity' => 2 ] ] ] ] );

    expect( $order->refresh()->tax_amount )->toBe( 2_000 )
        ->and( $order->total_amount )->toBe( 22_000 )
        ->and( $item->refresh()->tax_amount )->toBe( 2_000 )
        ->and( $item->total_amount )->toBe( 22_000 )
        ->and( $order->meta['tax_breakdown'][0]['amount'] )->toBe( 2_000 );
} );

it( 'keeps the tax when the edit sets it, or touches nothing it depends on', function ( array $edit, int $tax ): void {
    $order = taxedOrder();

    app( OrderEditService::class )->apply( $order, $edit );

    expect( $order->refresh()->tax_amount )->toBe( $tax );
} )->with( [
    'email only'       => [ [ 'email' => 'new@example.test' ], 1_000 ],
    'explicit tax'     => [ [ 'tax_amount' => 750, 'shipping_amount' => 500 ], 750 ],
] );

it( 'refuses a new line priced in another currency', function (): void {
    $order = taxedOrder();

    expect( fn () => app( OrderEditService::class )->apply( $order, [ 'items' => [ 'add' => [ [
        'product_id'          => Product::factory()->create()->id,
        'quantity'            => 1,
        'unit_price_amount'   => 500,
        'unit_price_currency' => 'EUR',
        'product_snapshot'    => [ 'name' => 'Mug' ],
    ] ] ] ] ) )->toThrow( InvalidArgumentException::class, 'EUR' );
} );

it( 'does not add tax on top of tax-inclusive prices', function (): void {
    $order = taxedOrder( [ 'meta' => [ 'prices_include_tax' => true ], 'total_amount' => 10_000 ] );
    $item  = $order->items()->sole();

    app( OrderEditService::class )->apply( $order, [ 'items' => [ 'change' => [ $item->id => [ 'quantity' => 2 ] ] ] ] );

    expect( $order->refresh()->total_amount )->toBe( 20_000 )
        ->and( $order->tax_amount )->toBeGreaterThan( 0 );
} );
