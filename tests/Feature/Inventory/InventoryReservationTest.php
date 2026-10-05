<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\InsufficientStockException;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->service = app( InventoryService::class );
} );

it( 'creates an inventory row and computes effective available as on-hand minus reserved', function (): void {
    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()->for( $product, 'stockable' )->create( [
        'quantity_on_hand'  => 5,
        'quantity_reserved' => 2,
    ] );

    expect( $item->availableQuantity() )->toBe( 3 );
} );

it( 'reserves stock, bumps quantity_reserved, and applies the configured TTL', function (): void {
    config()->set( 'artisanpack.ecommerce.checkout.reservation_ttl_minutes', 20 );
    Carbon::setTestNow( '2026-09-14 12:00:00' );

    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()->for( $product, 'stockable' )->create( [
        'quantity_on_hand' => 5,
    ] );
    $order         = new Order();
    $order->exists = true;
    $order->setAttribute( $order->getKeyName(), 999 );

    $reservation = $this->service->reserve( $item, $order, 2 );

    expect( $reservation->quantity )->toBe( 2 );
    expect( $reservation->expires_at->toDateTimeString() )->toBe( '2026-09-14 12:20:00' );
    expect( $item->fresh()->quantity_reserved )->toBe( 2 );
    expect( $item->fresh()->availableQuantity() )->toBe( 3 );

    Carbon::setTestNow();
} );

it( 'blocks a second reservation when the last unit is already spoken for', function (): void {
    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()->for( $product, 'stockable' )->create( [
        'quantity_on_hand' => 1,
    ] );
    $cartA         = new Order();
    $cartA->exists = true;
    $cartA->setAttribute( $cartA->getKeyName(), 1 );

    $cartB         = new Order();
    $cartB->exists = true;
    $cartB->setAttribute( $cartB->getKeyName(), 2 );

    $this->service->reserve( $item, $cartA, 1 );

    expect( fn () => $this->service->reserve( $item, $cartB, 1 ) )
        ->toThrow( InsufficientStockException::class );

    expect( $item->fresh()->quantity_reserved )->toBe( 1 );
    expect( InventoryReservation::query()->count() )->toBe( 1 );
} );

it( 'allows reservations past on-hand when the row allows backorders', function (): void {
    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()
        ->for( $product, 'stockable' )
        ->backorderable()
        ->create( [ 'quantity_on_hand' => 0 ] );
    $order         = new Order();
    $order->exists = true;
    $order->setAttribute( $order->getKeyName(), 1 );

    $reservation = $this->service->reserve( $item, $order, 5 );

    expect( $reservation->quantity )->toBe( 5 );
    expect( $item->fresh()->quantity_reserved )->toBe( 5 );
} );

it( 'ignores inventory checks when tracking is disabled', function (): void {
    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()
        ->for( $product, 'stockable' )
        ->untracked()
        ->create( [ 'quantity_on_hand' => 0 ] );
    $order         = new Order();
    $order->exists = true;
    $order->setAttribute( $order->getKeyName(), 1 );

    $reservation = $this->service->reserve( $item, $order, 3 );

    expect( $reservation->exists )->toBeTrue();
    expect( $item->fresh()->quantity_reserved )->toBe( 3 );
} );

it( 'rejects reservations with a non-positive quantity', function (): void {
    $product       = Product::factory()->simple()->create();
    $item          = InventoryItem::factory()->for( $product, 'stockable' )->create();
    $order         = new Order();
    $order->exists = true;
    $order->setAttribute( $order->getKeyName(), 1 );

    expect( fn () => $this->service->reserve( $item, $order, 0 ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'fires the inventory.reserved hook after a successful reservation', function (): void {
    $captured = null;
    addAction( 'ap.ecommerce.inventory.reserved', function ( InventoryReservation $reservation ) use ( &$captured ): void {
        $captured = $reservation->id;
    } );

    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()->for( $product, 'stockable' )->create( [
        'quantity_on_hand' => 5,
    ] );
    $order         = new Order();
    $order->exists = true;
    $order->setAttribute( $order->getKeyName(), 1 );

    $reservation = $this->service->reserve( $item, $order, 1 );

    expect( $captured )->toBe( $reservation->id );
} );
