<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Broadcasting\KanbanBoardBroadcast;
use ArtisanPackUI\Ecommerce\Events\OrderEdited;
use ArtisanPackUI\Ecommerce\Events\ShipmentCreated;
use ArtisanPackUI\Ecommerce\Kanban\KanbanCardRefresher;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderEdit;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/KanbanTestHelpers.php';

uses( RefreshDatabase::class );

/**
 * Turns broadcasting on and registers the refresh listeners.
 */
function enableCardRefresh(): void
{
    config()->set( 'artisanpack.ecommerce.kanban.broadcast', true );
    config()->set( 'artisanpack.ecommerce.kanban.auto_route', false );

    ( fn () => $this->registerKanbanListeners() )->call( new EcommerceServiceProvider( app() ) );
}

/**
 * The card-updated payloads broadcast so far.
 *
 * @return array<int, array<string, mixed>>
 */
function cardUpdates(): array
{
    return Event::dispatched( KanbanBoardBroadcast::class )
        ->map( fn ( array $args ) => $args[0]->broadcastWith() )
        ->filter( fn ( array $payload ): bool => 'card-updated' === $payload['type'] )
        ->values()
        ->all();
}

it( 'does nothing while broadcasting is off', function (): void {
    Event::fake( [ KanbanBoardBroadcast::class ] );
    $board = kanbanBoard( [], [ [ 'processing', 'A' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, kanbanColumn( $board, 'A' ) );

    expect( app( KanbanCardRefresher::class )->refresh( $order ) )->toBeFalse();
    Event::assertNotDispatched( KanbanBoardBroadcast::class );
} );

it( 'broadcasts to every active board the order is on', function (): void {
    enableCardRefresh();
    Event::fake( [ KanbanBoardBroadcast::class ] );

    $one      = kanbanBoard( [], [ [ 'processing', 'A' ] ] );
    $two      = kanbanBoard( [], [ [ 'processing', 'B' ] ] );
    $inactive = kanbanBoard( [ 'is_active' => false ], [ [ 'processing', 'C' ] ] );
    $order    = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, kanbanColumn( $one, 'A' ) );
    putOnBoard( $order, kanbanColumn( $two, 'B' ) );
    putOnBoard( $order, kanbanColumn( $inactive, 'C' ) );

    app( KanbanCardRefresher::class )->refresh( $order, 'printful:status' );

    $updates = cardUpdates();

    expect( array_column( $updates, 'board_id' ) )->toEqualCanonicalizing( [ $one->id, $two->id ] )
        ->and( $updates[0]['widget_key'] )->toBe( 'printful:status' )
        ->and( $updates[0]['order_id'] )->toBe( $order->id );
} );

it( 'debounces repeats in one transaction, and a whole-card refresh absorbs widget refreshes', function (): void {
    enableCardRefresh();
    Event::fake( [ KanbanBoardBroadcast::class ] );

    $board = kanbanBoard( [], [ [ 'processing', 'A' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, kanbanColumn( $board, 'A' ) );
    $refresher = app( KanbanCardRefresher::class );

    DB::transaction( function () use ( $refresher, $order ): void {
        expect( $refresher->refresh( $order, 'tags' ) )->toBeTrue()
            ->and( $refresher->refresh( $order, 'tags' ) )->toBeFalse()
            ->and( $refresher->refresh( $order ) )->toBeTrue()
            ->and( $refresher->refresh( $order, 'total' ) )->toBeFalse()
            ->and( $refresher->refresh( $order ) )->toBeFalse();
    } );

    expect( cardUpdates() )->toHaveCount( 2 );
} );

it( 'waits for the transaction to commit', function (): void {
    enableCardRefresh();
    Event::fake( [ KanbanBoardBroadcast::class ] );

    $board = kanbanBoard( [], [ [ 'processing', 'A' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, kanbanColumn( $board, 'A' ) );

    DB::transaction( function () use ( $order ): void {
        app( KanbanCardRefresher::class )->refresh( $order );

        expect( cardUpdates() )->toBe( [] );
    } );

    expect( cardUpdates() )->toHaveCount( 1 );
} );

it( 'refreshes on payment status, fulfillment status, and tag changes but not other edits', function ( array $change, int $expected ): void {
    enableCardRefresh();
    $board = kanbanBoard( [], [ [ 'processing', 'A' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing', 'meta' => [ 'tags' => [ 'rush' ] ] ] );
    putOnBoard( $order, kanbanColumn( $board, 'A' ) );

    Event::fake( [ KanbanBoardBroadcast::class ] );
    $order->fresh()->update( $change );

    expect( cardUpdates() )->toHaveCount( $expected );
} )->with( [
    'payment status'     => [ [ 'payment_status' => 'paid' ], 1 ],
    'fulfillment status' => [ [ 'fulfillment_status' => 'fulfilled' ], 1 ],
    'tags'               => [ [ 'meta' => [ 'tags' => [ 'rush', 'gift' ] ] ], 1 ],
    'other meta'         => [ [ 'meta' => [ 'tags' => [ 'rush' ], 'note' => 'x' ] ], 0 ],
    'customer note'      => [ [ 'customer_note' => 'Leave at door' ], 0 ],
] );

it( 'refreshes on an order edit', function (): void {
    enableCardRefresh();
    $board = kanbanBoard( [], [ [ 'processing', 'A' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, kanbanColumn( $board, 'A' ) );

    Event::fake( [ KanbanBoardBroadcast::class ] );
    Event::dispatch( new OrderEdited( $order, [], new OrderEdit() ) );

    expect( cardUpdates() )->toHaveCount( 1 );
    expect( OrderBoardAssignment::query()->count() )->toBe( 1 );
} );

it( 'refreshes again after a later change in the same request', function (): void {
    enableCardRefresh();
    Event::fake( [ KanbanBoardBroadcast::class ] );

    $board = kanbanBoard( [], [ [ 'processing', 'A' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, kanbanColumn( $board, 'A' ) );
    $refresher = app( KanbanCardRefresher::class );

    DB::transaction( fn () => $refresher->refresh( $order ) );
    DB::transaction( fn () => $refresher->refresh( $order ) );

    expect( cardUpdates() )->toHaveCount( 2 );
} );

it( 'refreshes when a shipment is created', function (): void {
    enableCardRefresh();
    $board = kanbanBoard( [], [ [ 'processing', 'A' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, kanbanColumn( $board, 'A' ) );

    Event::fake( [ KanbanBoardBroadcast::class ] );
    Event::dispatch( new ShipmentCreated( new Shipment( [ 'order_id' => $order->id ] ), $order ) );

    expect( cardUpdates() )->toHaveCount( 1 );
} );
