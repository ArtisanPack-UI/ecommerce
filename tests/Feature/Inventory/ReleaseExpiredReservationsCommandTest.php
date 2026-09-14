<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses( RefreshDatabase::class );

it( 'releases expired reservations, frees their reserved stock, and leaves live ones alone', function (): void {
    Carbon::setTestNow( '2026-09-14 12:00:00' );

    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()->for( $product, 'stockable' )->create( [
        'quantity_on_hand'  => 10,
        'quantity_reserved' => 6,
    ] );

    $expired = InventoryReservation::factory()
        ->for( $item, 'inventoryItem' )
        ->expired()
        ->create( [ 'quantity' => 4 ] );
    $live = InventoryReservation::factory()
        ->for( $item, 'inventoryItem' )
        ->create( [
            'quantity'   => 2,
            'expires_at' => Carbon::now()->addMinutes( 5 ),
        ] );

    $released = null;
    addAction( 'ap.ecommerce.inventory.reservationReleased', function ( InventoryReservation $reservation ) use ( &$released ): void {
        $released = $reservation->id;
    } );

    $this->artisan( 'ecommerce:release-expired-reservations' )
        ->expectsOutputToContain( 'Released 1 expired reservation(s).' )
        ->assertSuccessful();

    expect( InventoryReservation::query()->find( $expired->id ) )->toBeNull();
    expect( InventoryReservation::query()->find( $live->id ) )->not->toBeNull();
    expect( $item->fresh()->quantity_reserved )->toBe( 2 );
    expect( $released )->toBe( $expired->id );

    Carbon::setTestNow();
} );

it( 'never lets quantity_reserved go negative when releasing', function (): void {
    Carbon::setTestNow( '2026-09-14 12:00:00' );

    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()->for( $product, 'stockable' )->create( [
        'quantity_on_hand'  => 10,
        'quantity_reserved' => 1,
    ] );

    InventoryReservation::factory()
        ->for( $item, 'inventoryItem' )
        ->expired()
        ->create( [ 'quantity' => 5 ] );

    $this->artisan( 'ecommerce:release-expired-reservations' )->assertSuccessful();

    expect( $item->fresh()->quantity_reserved )->toBe( 0 );

    Carbon::setTestNow();
} );

it( 'is a no-op when no reservations have expired', function (): void {
    $product = Product::factory()->simple()->create();
    $item    = InventoryItem::factory()->for( $product, 'stockable' )->create( [
        'quantity_reserved' => 2,
    ] );

    InventoryReservation::factory()
        ->for( $item, 'inventoryItem' )
        ->create( [
            'quantity'   => 2,
            'expires_at' => Carbon::now()->addMinutes( 15 ),
        ] );

    $this->artisan( 'ecommerce:release-expired-reservations' )
        ->expectsOutputToContain( 'Released 0 expired reservation(s).' )
        ->assertSuccessful();

    expect( $item->fresh()->quantity_reserved )->toBe( 2 );
} );
