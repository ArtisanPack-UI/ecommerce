<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->service = app( InventoryService::class );
} );

it( 'adjusts quantity_on_hand and dispatches the adjusted action', function (): void {
    $captured = null;
    addAction( 'ap.ecommerce.inventory.adjusted', function ( InventoryItem $item, int $delta, int $newLevel ) use ( &$captured ): void {
        $captured = [ $item->id, $delta, $newLevel ];
    } );

    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()->for( $product, 'stockable' )->create( [
        'quantity_on_hand' => 10,
    ] );

    $refreshed = $this->service->adjust( $item, -3, 'shipment' );

    expect( $refreshed->quantity_on_hand )->toBe( 7 );
    expect( $captured )->toBe( [ $item->id, -3, 7 ] );
} );

it( 'lets the adjusting filter modify the delta before persist', function (): void {
    addFilter( 'ap.ecommerce.inventory.adjusting', fn ( int $delta ) => $delta * 2 );

    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()->for( $product, 'stockable' )->create( [
        'quantity_on_hand' => 10,
    ] );

    $refreshed = $this->service->adjust( $item, 1, 'intake' );

    expect( $refreshed->quantity_on_hand )->toBe( 12 );
} );

it( 'fires low-stock when crossing the threshold downward', function (): void {
    $captured = null;
    addAction( 'ap.ecommerce.inventory.lowStock', function ( InventoryItem $item, int $level ) use ( &$captured ): void {
        $captured = $level;
    } );

    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()->for( $product, 'stockable' )->create( [
        'quantity_on_hand'    => 10,
        'low_stock_threshold' => 3,
    ] );

    $this->service->adjust( $item, -8, 'shipment' );

    expect( $captured )->toBe( 2 );
} );

it( 'does not re-fire low-stock when already below the threshold', function (): void {
    $fired = 0;
    addAction( 'ap.ecommerce.inventory.lowStock', function () use ( &$fired ): void {
        ++$fired;
    } );

    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()->for( $product, 'stockable' )->create( [
        'quantity_on_hand'    => 2,
        'low_stock_threshold' => 3,
    ] );

    $this->service->adjust( $item, -1, 'shipment' );

    expect( $fired )->toBe( 0 );
} );

it( 'fires out-of-stock when on-hand hits zero from a positive count', function (): void {
    $fired = false;
    addAction( 'ap.ecommerce.inventory.outOfStock', function () use ( &$fired ): void {
        $fired = true;
    } );

    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()->for( $product, 'stockable' )->create( [
        'quantity_on_hand' => 1,
    ] );

    $this->service->adjust( $item, -1, 'shipment' );

    expect( $fired )->toBeTrue();
} );
