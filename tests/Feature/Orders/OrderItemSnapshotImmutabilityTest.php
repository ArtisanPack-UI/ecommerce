<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'persists the product_snapshot json shape verbatim', function (): void {
    $snapshot = [
        'name'    => 'Widget',
        'sku'     => 'WID-001',
        'type'    => 'simple',
        'options' => [
            [ 'key' => 'size', 'label' => 'Size', 'value' => 'L', 'value_label' => 'Large' ],
        ],
    ];

    $item = OrderItem::factory()->create( [ 'product_snapshot' => $snapshot ] );

    expect( OrderItem::query()->find( $item->id )->product_snapshot )->toBe( $snapshot );
} );

it( 'throws when product_snapshot is modified on an existing row', function (): void {
    $item = OrderItem::factory()->create( [
        'product_snapshot' => [ 'name' => 'Widget', 'sku' => 'WID-001', 'type' => 'simple', 'options' => [] ],
    ] );

    $item->product_snapshot = [ 'name' => 'Tampered', 'sku' => 'X', 'type' => 'simple', 'options' => [] ];

    expect( fn () => $item->save() )
        ->toThrow( LogicException::class, 'order_items.product_snapshot is immutable' );

    expect( OrderItem::query()->find( $item->id )->product_snapshot[ 'name' ] )->toBe( 'Widget' );
} );

it( 'allows saving other columns without triggering the snapshot guard', function (): void {
    $item = OrderItem::factory()->create( [
        'fulfillment_status' => 'unfulfilled',
    ] );

    $item->fulfillment_status = 'fulfilled';
    $item->save();

    expect( OrderItem::query()->find( $item->id )->fulfillment_status )->toBe( 'fulfilled' );
} );

it( 'allows re-saving with the same product_snapshot value', function (): void {
    $snapshot = [ 'name' => 'Widget', 'sku' => 'WID-001', 'type' => 'simple', 'options' => [] ];
    $item     = OrderItem::factory()->create( [ 'product_snapshot' => $snapshot ] );

    $item->product_snapshot = $snapshot;
    $item->quantity         = 2;
    $item->save();

    expect( OrderItem::query()->find( $item->id )->quantity )->toBe( 2 );
} );

it( 'blocks a query-builder bulk update that touches product_snapshot', function (): void {
    $item = OrderItem::factory()->create( [
        'product_snapshot' => [ 'name' => 'Widget', 'sku' => 'WID-001', 'type' => 'simple', 'options' => [] ],
    ] );

    expect( fn () => OrderItem::query()->where( 'id', $item->id )->update( [
        'product_snapshot' => [ 'name' => 'Tampered', 'sku' => 'X', 'type' => 'simple', 'options' => [] ],
    ] ) )->toThrow( LogicException::class, 'immutable' );

    expect( OrderItem::query()->find( $item->id )->product_snapshot[ 'name' ] )->toBe( 'Widget' );
} );

it( 'allows a query-builder bulk update on columns other than product_snapshot', function (): void {
    $item = OrderItem::factory()->create( [ 'fulfillment_status' => 'unfulfilled' ] );

    OrderItem::query()->where( 'id', $item->id )->update( [ 'fulfillment_status' => 'fulfilled' ] );

    expect( OrderItem::query()->find( $item->id )->fulfillment_status )->toBe( 'fulfilled' );
} );
