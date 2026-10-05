<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\KanbanCardMoved;
use ArtisanPackUI\Ecommerce\Events\OrderStatusChanged;
use ArtisanPackUI\Ecommerce\Exceptions\IncompatibleBoardSubstatusException;
use ArtisanPackUI\Ecommerce\Exceptions\InvalidOrderStatusTransitionException;
use ArtisanPackUI\Ecommerce\Exceptions\KanbanOperationException;
use ArtisanPackUI\Ecommerce\Exceptions\SubstatusTransitionRejectedException;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Services\KanbanBoardService;
use ArtisanPackUI\Ecommerce\Services\OrderStatusMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/KanbanTestHelpers.php';

uses( RefreshDatabase::class );

afterEach( function (): void {
    removeAllFilters( 'ap.ecommerce.kanban.cardMoving' );
    removeAllFilters( 'ap.ecommerce.order.canTransitionSubstatus' );
    removeAllActions( 'ap.ecommerce.kanban.cardMoved' );
} );

/**
 * Production (Printing → Printed) and Shipping (Awaiting label → Shipped)
 * boards with a processing order on both.
 *
 * @return array{0: Order, 1: ArtisanPackUI\Ecommerce\Models\KanbanBoard, 2: ArtisanPackUI\Ecommerce\Models\KanbanBoard}
 */
function orderOnTwoBoards(): array
{
    $production = kanbanBoard( [ 'key' => 'production' ], [ [ 'processing', 'Printing' ], [ 'processing', 'QC' ], [ 'complete', 'Printed', true ], [ 'cancelled', 'Scrapped', true ] ] );
    $shipping   = kanbanBoard( [ 'key' => 'shipping' ], [ [ 'processing', 'Awaiting label' ], [ 'complete', 'Shipped', true ] ] );
    $order      = kanbanOrder( [ 'system_status' => 'processing' ] );

    putOnBoard( $order, kanbanColumn( $production, 'Printing' ) );
    putOnBoard( $order, kanbanColumn( $shipping, 'Awaiting label' ) );

    return [ $order, $production, $shipping ];
}

function cardOn( Order $order, int $boardId ): OrderBoardAssignment
{
    return OrderBoardAssignment::query()->with( 'substatus' )->where( 'order_id', $order->id )->where( 'board_id', $boardId )->firstOrFail();
}

it( 'moves only the card on the moved board and fires the move hook and event', function (): void {
    [ $order, $production, $shipping ] = orderOnTwoBoards();
    $qc                                = kanbanColumn( $production, 'QC' );
    $hooked                            = null;

    Carbon::setTestNow( '2026-10-01 09:00:00' );
    Event::fake( [ KanbanCardMoved::class ] );
    addAction( 'ap.ecommerce.kanban.cardMoved', function ( Order $o, KanbanColumn $from, KanbanColumn $to ) use ( &$hooked ): void {
        $hooked = [ $from->displayLabel(), $to->displayLabel() ];
    } );

    $card = app( KanbanBoardService::class )->move( $order, $qc, 7, 'artwork approved' );

    expect( $card->fresh()->substatus_id )->toBe( $qc->substatus_id )
        ->and( $card->fresh()->moved_at->toDateTimeString() )->toBe( '2026-10-01 09:00:00' )
        ->and( cardOn( $order, $shipping->id )->substatus->label )->toBe( 'Awaiting label' )
        ->and( $hooked )->toBe( [ 'Printing', 'QC' ] )
        ->and( $order->fresh()->system_status )->toBe( 'processing' );

    Event::assertDispatched( KanbanCardMoved::class, fn ( KanbanCardMoved $e ): bool => $e->board->is( $production ) && $e->to->is( $qc ) && 'Printing' === $e->from->displayLabel() );

    $entry = OrderTimelineEntry::query()->where( 'event_type', 'order.substatus_changed' )->latest( 'id' )->first();

    expect( $entry->payload['board_id'] )->toBe( $production->id )
        ->and( $entry->payload['reason'] )->toBe( 'artwork approved' )
        ->and( $entry->actor_user_id )->toBe( 7 );

    Carbon::setTestNow();
} );

it( 'treats a move into the current column as a no-op', function (): void {
    [ $order, $production ] = orderOnTwoBoards();

    Event::fake( [ KanbanCardMoved::class ] );

    app( KanbanBoardService::class )->move( $order, kanbanColumn( $production, 'Printing' ) );

    Event::assertNotDispatched( KanbanCardMoved::class );
} );

it( 'refuses to move a card for an order that is not on the board', function (): void {
    $board = kanbanBoard();

    expect( fn () => app( KanbanBoardService::class )->move( kanbanOrder(), $board->columns->first() ) )
        ->toThrow( KanbanOperationException::class, 'is not on board' );
} );

it( 'enforces the target column WIP limit', function (): void {
    [ $order, $production ] = orderOnTwoBoards();
    $qc                     = kanbanColumn( $production, 'QC' );
    $qc->update( [ 'wip_limit' => 1 ] );

    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), $qc );

    try {
        app( KanbanBoardService::class )->move( $order, $qc );
        $this->fail( 'Expected the WIP limit to reject the move.' );
    } catch ( KanbanOperationException $e ) {
        expect( $e->errorCode )->toBe( 'wip-limit-reached' );
    }
} );

it( 'lets the cardMoving filter veto or redirect a move', function (): void {
    [ $order, $production ] = orderOnTwoBoards();
    $service                = app( KanbanBoardService::class );

    addFilter( 'ap.ecommerce.kanban.cardMoving', fn (): ?array => null );

    expect( fn () => $service->move( $order, kanbanColumn( $production, 'QC' ) ) )->toThrow( KanbanOperationException::class, 'rejected' );

    removeAllFilters( 'ap.ecommerce.kanban.cardMoving' );
    $qc = kanbanColumn( $production, 'QC' );
    addFilter( 'ap.ecommerce.kanban.cardMoving', fn ( array $move ): array => [ ...$move, 'to_column_id' => $qc->id ] );

    $card = $service->move( $order, kanbanColumn( $production, 'Printed' ) );

    expect( $card->fresh()->substatus_id )->toBe( $qc->substatus_id );
} );

it( 'holds the order at processing until every board reaches a terminal complete column', function (): void {
    [ $order, $production, $shipping ] = orderOnTwoBoards();
    $service                           = app( KanbanBoardService::class );

    Event::fake( [ OrderStatusChanged::class ] );

    $service->move( $order, kanbanColumn( $production, 'Printed' ) );

    expect( $order->fresh()->system_status )->toBe( 'processing' );
    Event::assertNotDispatched( OrderStatusChanged::class );

    $service->move( $order, kanbanColumn( $shipping, 'Shipped' ) );

    $fresh = $order->fresh();

    expect( $fresh->system_status )->toBe( 'complete' )
        ->and( OrderSubstatus::query()->find( $fresh->substatus_id )->system_status )->toBe( 'complete' );
    Event::assertDispatched( OrderStatusChanged::class, fn ( OrderStatusChanged $e ): bool => 'processing' === $e->from && 'complete' === $e->to );
} );

it( 'moves a pending order to processing when any board starts work on it', function (): void {
    $board = kanbanBoard( [], [ [ 'pending', 'New' ], [ 'processing', 'Working' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'pending', 'substatus_id' => OrderSubstatus::query()->where( 'key', 'awaiting-payment' )->value( 'id' ) ] );

    putOnBoard( $order, kanbanColumn( $board, 'New' ) );

    app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'Working' ) );

    $fresh = $order->fresh();

    expect( $fresh->system_status )->toBe( 'processing' )
        ->and( OrderSubstatus::query()->find( $fresh->substatus_id )->key )->toBe( 'in-progress' );
} );

it( 'moves the whole order into an exit status from any board', function (): void {
    [ $order, $production ] = orderOnTwoBoards();

    app( KanbanBoardService::class )->move( $order, kanbanColumn( $production, 'Scrapped' ) );

    expect( $order->fresh()->system_status )->toBe( 'cancelled' );
} );

it( 'rolls back the move when the roll-up would be an illegal status transition', function (): void {
    $board = kanbanBoard( [], [ [ 'pending', 'New' ], [ 'complete', 'Done', true ] ] );
    $order = kanbanOrder( [ 'system_status' => 'pending' ] );
    $card  = putOnBoard( $order, kanbanColumn( $board, 'New' ) );

    expect( fn () => app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'Done' ) ) )
        ->toThrow( InvalidOrderStatusTransitionException::class );

    expect( $card->fresh()->substatus_id )->toBe( kanbanColumn( $board, 'New' )->substatus_id )
        ->and( $order->fresh()->system_status )->toBe( 'pending' );
} );

it( 'refuses a forward-chain column once the order has exited', function (): void {
    $board = kanbanBoard( [], [ [ 'cancelled', 'Cancelled', true ], [ 'processing', 'Working' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'cancelled' ] );

    putOnBoard( $order, kanbanColumn( $board, 'Cancelled' ) );

    // Alone on its board, the roll-up asks for cancelled → processing.
    expect( fn () => app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'Working' ) ) )
        ->toThrow( InvalidOrderStatusTransitionException::class );

    // With another board already on an exit column there is no roll-up; the
    // column simply can't hold a cancelled order.
    $other = kanbanBoard( [], [ [ 'cancelled', 'Void', true ] ] );
    putOnBoard( $order, kanbanColumn( $other, 'Void' ) );

    expect( fn () => app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'Working' ) ) )
        ->toThrow( IncompatibleBoardSubstatusException::class );
} );

it( 'honours the canTransitionSubstatus veto', function (): void {
    [ $order, $production ] = orderOnTwoBoards();

    addFilter( 'ap.ecommerce.order.canTransitionSubstatus', fn (): bool => false );

    expect( fn () => app( KanbanBoardService::class )->move( $order, kanbanColumn( $production, 'QC' ) ) )
        ->toThrow( SubstatusTransitionRejectedException::class );

    expect( cardOn( $order, $production->id )->substatus->label )->toBe( 'Printing' );
} );

it( 'rolls board sub-statuses up per the multi-board rules', function (): void {
    $machine = new OrderStatusMachine();
    $sub     = fn ( string $status, bool $terminal = false ): OrderSubstatus => new OrderSubstatus( [ 'system_status' => $status, 'is_terminal' => $terminal ] );

    expect( $machine->rollUpBoardStatus( [] ) )->toBeNull()
        ->and( $machine->rollUpBoardStatus( [ $sub( 'pending' ), $sub( 'pending' ) ] ) )->toBe( 'pending' )
        ->and( $machine->rollUpBoardStatus( [ $sub( 'pending' ), $sub( 'processing' ) ] ) )->toBe( 'processing' )
        ->and( $machine->rollUpBoardStatus( [ $sub( 'complete', true ), $sub( 'processing' ) ] ) )->toBe( 'processing' )
        ->and( $machine->rollUpBoardStatus( [ $sub( 'complete', true ), $sub( 'complete' ) ] ) )->toBe( 'processing' )
        ->and( $machine->rollUpBoardStatus( [ $sub( 'complete', true ), $sub( 'complete', true ) ] ) )->toBe( 'complete' )
        ->and( $machine->rollUpBoardStatus( [ $sub( 'complete', true ), $sub( 'cancelled', true ) ] ) )->toBeNull();

    $order = new Order( [ 'system_status' => 'processing' ] );

    expect( $machine->isBoardAssignmentCompatible( $order, $sub( 'complete' ) ) )->toBeTrue()
        ->and( $machine->isBoardAssignmentCompatible( $order, $sub( 'pending' ) ) )->toBeTrue()
        ->and( $machine->isBoardAssignmentCompatible( $order, $sub( 'cancelled' ) ) )->toBeFalse();
} );

it( 'no longer reports boards that lead or lag the order within the forward chain as drift', function (): void {
    [ $order, $production ] = orderOnTwoBoards();

    app( KanbanBoardService::class )->move( $order, kanbanColumn( $production, 'Printed' ) );

    $this->artisan( 'ecommerce:audit-order-status' )->assertExitCode( 0 );

    $order->update( [ 'system_status' => 'refunded' ] );

    $this->artisan( 'ecommerce:audit-order-status' )->assertExitCode( 1 );
} );
