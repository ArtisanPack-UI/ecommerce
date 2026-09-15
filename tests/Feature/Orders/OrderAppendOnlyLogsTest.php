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

it( 'rejects a bulk update issued through the timeline query builder', function (): void {
    $entry = OrderTimelineEntry::factory()->create();

    expect( fn () => OrderTimelineEntry::query()->where( 'id', $entry->id )->update( [ 'event_type' => 'tampered' ] ) )
        ->toThrow( LogicException::class, 'append-only' );

    expect( OrderTimelineEntry::query()->find( $entry->id )->event_type )->not->toBe( 'tampered' );
} );

it( 'rejects a bulk update issued through the order-edit query builder', function (): void {
    $edit = OrderEdit::factory()->create();

    expect( fn () => OrderEdit::query()->where( 'id', $edit->id )->update( [ 'reason' => 'tampered' ] ) )
        ->toThrow( LogicException::class, 'append-only' );

    expect( OrderEdit::query()->find( $edit->id )->reason )->not->toBe( 'tampered' );
} );

it( 'rejects a direct delete on a timeline entry', function (): void {
    $entry = OrderTimelineEntry::factory()->create();

    expect( fn () => $entry->delete() )
        ->toThrow( LogicException::class, 'append-only' );

    expect( OrderTimelineEntry::query()->find( $entry->id ) )->not->toBeNull();
} );

it( 'rejects a direct delete on an order edit', function (): void {
    $edit = OrderEdit::factory()->create();

    expect( fn () => $edit->delete() )
        ->toThrow( LogicException::class, 'append-only' );

    expect( OrderEdit::query()->find( $edit->id ) )->not->toBeNull();
} );

it( 'rejects a bulk delete against the timeline query builder', function (): void {
    OrderTimelineEntry::factory()->count( 2 )->create();

    expect( fn () => OrderTimelineEntry::query()->delete() )
        ->toThrow( LogicException::class, 'append-only' );

    expect( OrderTimelineEntry::query()->count() )->toBe( 2 );
} );

it( 'rejects a bulk delete against the order-edit query builder', function (): void {
    OrderEdit::factory()->count( 2 )->create();

    expect( fn () => OrderEdit::query()->delete() )
        ->toThrow( LogicException::class, 'append-only' );

    expect( OrderEdit::query()->count() )->toBe( 2 );
} );

it( 'still cascade-removes timeline entries and edits when the owning order is deleted', function (): void {
    $order = Order::factory()->create();

    OrderTimelineEntry::factory()->for( $order )->count( 2 )->create();
    OrderEdit::factory()->for( $order )->create();

    $order->delete();

    expect( OrderTimelineEntry::query()->where( 'order_id', $order->id )->count() )->toBe( 0 );
    expect( OrderEdit::query()->where( 'order_id', $order->id )->count() )->toBe( 0 );
} );
