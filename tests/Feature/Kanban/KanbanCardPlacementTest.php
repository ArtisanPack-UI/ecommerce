<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\KanbanCardMoved;
use ArtisanPackUI\Ecommerce\Events\KanbanCardReordered;
use ArtisanPackUI\Ecommerce\Kanban\KanbanCardPlacement;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Services\KanbanBoardService;
use ArtisanPackUI\Ecommerce\Services\KanbanRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/KanbanTestHelpers.php';

uses( RefreshDatabase::class );

afterEach( function (): void {
    removeAllFilters( 'ap.ecommerce.kanban.cardMoving' );
    removeAllActions( 'ap.ecommerce.kanban.cardReordered' );
    removeAllActions( 'ap.ecommerce.kanban.cardMoved' );
} );

/**
 * A two-column processing board (Printing, Packing) with `$printing` orders
 * assigned to Printing through the routing service, in order.
 *
 * @return array{0: KanbanBoard, 1: array<int, Order>}
 */
function placementBoard( int $printing = 3 ): array
{
    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ], [ 'processing', 'Packing' ], [ 'cancelled', 'Scrapped', true ] ] );
    $orders = [];

    for ( $i = 0; $i < $printing; $i++ ) {
        $orders[] = $order = kanbanOrder( [ 'system_status' => 'processing', 'order_number' => 'P' . ( $i + 1 ) ] );
        app( KanbanRoutingService::class )->assign( $order, $board, kanbanColumn( $board, 'Printing' ) );
    }

    return [ $board, $orders ];
}

/**
 * Order numbers in `$column`, in display order.
 *
 * @return array<int, string>
 */
function columnOrder( KanbanColumn $column ): array
{
    return OrderBoardAssignment::query()
        ->active()
        ->where( 'board_id', $column->board_id )
        ->where( 'substatus_id', $column->substatus_id )
        ->orderBy( 'position' )
        ->orderBy( 'assigned_at' )
        ->orderBy( 'id' )
        ->with( 'order' )
        ->get()
        ->map( fn ( OrderBoardAssignment $card ): string => $card->order->order_number )
        ->all();
}

it( 'adds the position column with an index and back-fills existing cards in column order', function (): void {
    $board    = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $printing = kanbanColumn( $board, 'Printing' );

    foreach ( [ 'B' => 1, 'A' => 3, 'C' => 0 ] as $number => $daysAgo ) {
        putOnBoard( kanbanOrder( [ 'system_status' => 'processing', 'order_number' => $number ] ), $printing )
            ->forceFill( [ 'moved_at' => Carbon::now()->subDays( $daysAgo ) ] )->save();
    }

    $migration = require __DIR__ . '/../../../database/migrations/2026_01_01_000062_add_position_to_ecommerce_order_board_assignments_table.php';
    $migration->down();

    expect( Schema::hasColumn( 'ecommerce_order_board_assignments', 'position' ) )->toBeFalse();

    $migration->up();

    expect( Schema::hasColumn( 'ecommerce_order_board_assignments', 'position' ) )->toBeTrue()
        ->and( Schema::hasIndex( 'ecommerce_order_board_assignments', 'ecommerce_order_board_assignments_position_idx' ) )->toBeTrue()
        ->and( OrderBoardAssignment::query()->orderBy( 'position' )->with( 'order' )->get()->map( fn ( $card ): array => [ $card->order->order_number, $card->position ] )->all() )
        ->toBe( [ [ 'A', 0 ], [ 'B', 1 ], [ 'C', 2 ] ] );
} );

it( 'adds new cards at the bottom of the entry column by default, or the top when configured', function (): void {
    [ $board ] = placementBoard( 2 );
    $printing  = kanbanColumn( $board, 'Printing' );

    expect( columnOrder( $printing ) )->toBe( [ 'P1', 'P2' ] );

    config()->set( 'artisanpack.ecommerce.kanban.new_card_position', 'top' );
    app( KanbanRoutingService::class )->assign( kanbanOrder( [ 'system_status' => 'processing', 'order_number' => 'RUSH' ] ), $board, $printing );

    expect( columnOrder( $printing ) )->toBe( [ 'RUSH', 'P1', 'P2' ] );
} );

it( 'places a card moved to another column before, after, at the top of, or at the bottom of its cards', function (): void {
    [ $board, $orders ] = placementBoard( 5 );
    $packing            = kanbanColumn( $board, 'Packing' );
    $moves              = app( KanbanBoardService::class );

    $moves->move( $orders[0], $packing );
    $moves->move( $orders[1], $packing );

    expect( columnOrder( $packing ) )->toBe( [ 'P1', 'P2' ] );

    $moves->move( $orders[2], $packing, placement: KanbanCardPlacement::before( $orders[1]->id ) );
    expect( columnOrder( $packing ) )->toBe( [ 'P1', 'P3', 'P2' ] );

    $moves->move( $orders[3], $packing, placement: KanbanCardPlacement::top() );
    expect( columnOrder( $packing ) )->toBe( [ 'P4', 'P1', 'P3', 'P2' ] );

    $moves->move( $orders[4], $packing, placement: KanbanCardPlacement::after( $orders[0]->id ) );
    expect( columnOrder( $packing ) )->toBe( [ 'P4', 'P1', 'P5', 'P3', 'P2' ] );
} );

it( 'reorders within a column without a status change, WIP check, or automations', function (): void {
    [ $board, $orders ] = placementBoard( 3 );
    $printing           = kanbanColumn( $board, 'Printing' );
    $printing->update( [ 'wip_limit' => 3 ] );
    $hooked             = null;

    Event::fake( [ KanbanCardMoved::class, KanbanCardReordered::class ] );
    addAction( 'ap.ecommerce.kanban.cardReordered', function ( Order $order, KanbanColumn $column, KanbanBoard $b, KanbanCardPlacement $placement ) use ( &$hooked ): void {
        $hooked = [ $order->order_number, $column->displayLabel(), $placement->mode ];
    } );

    $timeline = OrderTimelineEntry::query()->count();
    $movedAt  = OrderBoardAssignment::query()->where( 'order_id', $orders[2]->id )->value( 'moved_at' );

    $card = app( KanbanBoardService::class )->move( $orders[2], $printing, 1, null, KanbanCardPlacement::top() );

    expect( columnOrder( $printing ) )->toBe( [ 'P3', 'P1', 'P2' ] )
        ->and( $card->substatus_id )->toBe( $printing->substatus_id )
        ->and( (string) $card->fresh()->moved_at )->toBe( (string) $movedAt )
        ->and( OrderTimelineEntry::query()->count() )->toBe( $timeline )
        ->and( $hooked )->toBe( [ 'P3', 'Printing', 'top' ] );

    Event::assertDispatched( KanbanCardReordered::class, fn ( KanbanCardReordered $e ): bool => $e->column->is( $printing ) && $e->order->is( $orders[2] ) );
    Event::assertNotDispatched( KanbanCardMoved::class );

    app( KanbanBoardService::class )->move( $orders[2], $printing, placement: KanbanCardPlacement::after( $orders[1]->id ) );
    expect( columnOrder( $printing ) )->toBe( [ 'P1', 'P2', 'P3' ] );

    app( KanbanBoardService::class )->move( $orders[0], $printing, placement: KanbanCardPlacement::bottom() );
    expect( columnOrder( $printing ) )->toBe( [ 'P2', 'P3', 'P1' ] );
} );

it( 'treats a same-column move without a placement, or to the same place, as a no-op', function (): void {
    [ $board, $orders ] = placementBoard( 3 );
    $printing           = kanbanColumn( $board, 'Printing' );

    Event::fake( [ KanbanCardMoved::class, KanbanCardReordered::class ] );

    app( KanbanBoardService::class )->move( $orders[1], $printing );
    app( KanbanBoardService::class )->move( $orders[1], $printing, placement: KanbanCardPlacement::after( $orders[0]->id ) );
    app( KanbanBoardService::class )->move( $orders[1], $printing, placement: KanbanCardPlacement::before( $orders[1]->id ) );

    expect( columnOrder( $printing ) )->toBe( [ 'P1', 'P2', 'P3' ] );
    Event::assertNothingDispatched();
} );

it( 'places a card at the bottom when its before / after neighbour is not in the column', function (): void {
    [ $board, $orders ] = placementBoard( 3 );
    $printing           = kanbanColumn( $board, 'Printing' );
    $stranger           = kanbanOrder( [ 'system_status' => 'processing' ] );

    app( KanbanBoardService::class )->move( $orders[0], $printing, placement: KanbanCardPlacement::before( $stranger->id ) );

    expect( columnOrder( $printing ) )->toBe( [ 'P2', 'P3', 'P1' ] );
} );

it( 'passes the placement to cardMoving, which may change it', function (): void {
    [ $board, $orders ] = placementBoard( 3 );
    $printing           = kanbanColumn( $board, 'Printing' );
    $seen               = null;

    addFilter( 'ap.ecommerce.kanban.cardMoving', function ( array $move ) use ( &$seen ): array {
        $seen              = $move['placement'];
        $move['placement'] = [ 'mode' => 'top', 'order_id' => null ];

        return $move;
    } );

    app( KanbanBoardService::class )->move( $orders[2], $printing, placement: KanbanCardPlacement::after( $orders[0]->id ) );

    expect( $seen )->toBe( [ 'mode' => 'after', 'order_id' => $orders[0]->id ] )
        ->and( columnOrder( $printing ) )->toBe( [ 'P3', 'P1', 'P2' ] );
} );

it( 'keeps positions distinct through a run of reorders and moves', function (): void {
    [ $board, $orders ] = placementBoard( 6 );
    $printing           = kanbanColumn( $board, 'Printing' );
    $packing            = kanbanColumn( $board, 'Packing' );
    $moves              = app( KanbanBoardService::class );

    $moves->move( $orders[5], $printing, placement: KanbanCardPlacement::top() );
    $moves->move( $orders[0], $printing, placement: KanbanCardPlacement::after( $orders[3]->id ) );
    $moves->move( $orders[2], $packing );
    $moves->move( $orders[4], $printing, placement: KanbanCardPlacement::before( $orders[5]->id ) );
    $moves->move( $orders[2], $printing, placement: KanbanCardPlacement::after( $orders[1]->id ) );

    $positions = OrderBoardAssignment::query()->active()->where( 'substatus_id', $printing->substatus_id )->pluck( 'position' )->all();

    expect( columnOrder( $printing ) )->toBe( [ 'P5', 'P6', 'P2', 'P3', 'P4', 'P1' ] )
        ->and( count( array_unique( $positions ) ) )->toBe( count( $positions ) );
} );

it( 'reads and writes the array form of a placement', function (): void {
    expect( KanbanCardPlacement::fromArray( [ 'mode' => 'before', 'order_id' => '7' ] )?->toArray() )->toBe( [ 'mode' => 'before', 'order_id' => 7 ] )
        ->and( KanbanCardPlacement::fromArray( [ 'mode' => 'after' ] ) )->toBeNull()
        ->and( KanbanCardPlacement::fromArray( [ 'mode' => 'sideways' ] ) )->toBeNull()
        ->and( KanbanCardPlacement::fromArray( 'top' ) )->toBeNull()
        ->and( KanbanCardPlacement::end( 'top' )->mode )->toBe( 'top' )
        ->and( KanbanCardPlacement::end( 'anything' )->mode )->toBe( 'bottom' );
} );
