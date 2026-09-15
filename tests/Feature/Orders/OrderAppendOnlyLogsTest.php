<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderEdit;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'stamps created_at automatically on a timeline entry insert', function (): void {
    $order = Order::factory()->create();

    $entry = OrderTimelineEntry::query()->create( [
        'order_id'   => $order->id,
        'event_type' => 'order.placed',
        'payload'    => [ 'source' => 'checkout' ],
    ] );

    expect( $entry->created_at )->not->toBeNull();
    expect( $entry->payload )->toBe( [ 'source' => 'checkout' ] );
} );

it( 'rejects any update to a timeline entry', function (): void {
    $entry = OrderTimelineEntry::factory()->create();

    $entry->event_type = 'tampered';

    expect( fn () => $entry->save() )
        ->toThrow( LogicException::class, 'append-only' );
} );

it( 'stamps created_at automatically on an order edit insert', function (): void {
    $order = Order::factory()->create();

    $edit = OrderEdit::query()->create( [
        'order_id'          => $order->id,
        'reason'            => 'customer asked to swap size',
        'diff'              => [ 'items' => [ 'changed' => [ 1 ] ] ],
        'pre_edit_snapshot' => [ 'order' => [ 'total_amount' => 5_000 ] ],
    ] );

    expect( $edit->created_at )->not->toBeNull();
    expect( $edit->diff )->toBe( [ 'items' => [ 'changed' => [ 1 ] ] ] );
} );

it( 'rejects any update to an order edit row', function (): void {
    $edit = OrderEdit::factory()->create();

    $edit->reason = 'tampered';

    expect( fn () => $edit->save() )
        ->toThrow( LogicException::class, 'append-only' );
} );
