<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\KanbanBoardConfigChanged;
use ArtisanPackUI\Ecommerce\Exceptions\KanbanColumnNotEmptyException;
use ArtisanPackUI\Ecommerce\Exceptions\KanbanOperationException;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Services\KanbanColumnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

require_once __DIR__ . '/KanbanTestHelpers.php';
require_once __DIR__ . '/../Api/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'creates a column at the end of the board and announces the change', function (): void {
    Event::fake( [ KanbanBoardConfigChanged::class ] );
    $fired = [];
    addAction( 'ap.ecommerce.kanban.columnCreated', function ( KanbanColumn $column ) use ( &$fired ): void {
        $fired[] = $column->id;
    } );

    $board     = kanbanBoard( [], [ [ 'processing', 'Printing' ], [ 'processing', 'Packing' ] ] );
    $substatus = OrderSubstatus::factory()->forSystemStatus( 'processing' )->create( [ 'label' => 'QC' ] );

    $column = app( KanbanColumnService::class )->create( $board, [ 'substatus_id' => $substatus->id, 'color_override' => '#10B981', 'wip_limit' => 10 ], 7 );

    expect( $column->position )->toBe( 2 )
        ->and( $column->color_override )->toBe( '#10B981' )
        ->and( $fired )->toBe( [ $column->id ] );

    Event::assertDispatched( KanbanBoardConfigChanged::class, fn ( KanbanBoardConfigChanged $event ): bool => KanbanBoardConfigChanged::COLUMN_CREATED === $event->change
        && $event->board->is( $board )
        && $column->id === $event->columnId
        && 7 === $event->actorUserId );
} );

it( 'rejects invalid column data', function ( array $data, string $field ): void {
    $board = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );

    if ( 'substatus_id' === $field ) {
        $data['substatus_id'] = kanbanColumn( $board, 'Printing' )->substatus_id;
    } else {
        $data['substatus_id'] = OrderSubstatus::factory()->forSystemStatus( 'processing' )->create()->id;
    }

    try {
        app( KanbanColumnService::class )->create( $board, $data );
        $this->fail( 'Expected a validation error.' );
    } catch ( ValidationException $e ) {
        expect( $e->errors() )->toHaveKey( $field );
    }
} )->with( [
    'duplicate sub-status' => [ [], 'substatus_id' ],
    'bad color'            => [ [ 'color_override' => 'green' ], 'color_override' ],
    'zero WIP limit'       => [ [ 'wip_limit' => 0 ], 'wip_limit' ],
    'unknown widget'       => [ [ 'card_widgets' => [ 'nope' ] ], 'card_widgets.0' ],
] );

it( 'updates a column and refuses a sub-status change while it has cards', function (): void {
    Event::fake( [ KanbanBoardConfigChanged::class ] );

    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = kanbanColumn( $board, 'Printing' );

    $updated = app( KanbanColumnService::class )->update( $column, [ 'label_override' => 'Print queue', 'wip_limit' => 10, 'icon_override' => 'printer' ] );

    expect( $updated->displayLabel() )->toBe( 'Print queue' )->and( $updated->wip_limit )->toBe( 10 );
    Event::assertDispatched( KanbanBoardConfigChanged::class, fn ( KanbanBoardConfigChanged $event ): bool => KanbanBoardConfigChanged::COLUMN_UPDATED === $event->change );

    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), $column );
    $other = OrderSubstatus::factory()->forSystemStatus( 'processing' )->create();

    expect( fn () => app( KanbanColumnService::class )->update( $column->fresh(), [ 'substatus_id' => $other->id ] ) )
        ->toThrow( KanbanOperationException::class );
} );

it( 'does not announce an update that changed nothing', function (): void {
    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = kanbanColumn( $board, 'Printing' );

    Event::fake( [ KanbanBoardConfigChanged::class ] );
    app( KanbanColumnService::class )->update( $column, [ 'position' => $column->position ] );

    Event::assertNotDispatched( KanbanBoardConfigChanged::class );
} );

it( 'reorders every column in one write', function (): void {
    Event::fake( [ KanbanBoardConfigChanged::class ] );
    $board = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ], [ 'processing', 'C' ] ] );
    $ids   = [ kanbanColumn( $board, 'C' )->id, kanbanColumn( $board, 'A' )->id, kanbanColumn( $board, 'B' )->id ];

    $columns = app( KanbanColumnService::class )->reorder( $board, $ids );

    expect( $columns->modelKeys() )->toBe( $ids )
        ->and( $board->columns()->pluck( 'id' )->all() )->toBe( $ids );
    Event::assertDispatched( KanbanBoardConfigChanged::class, fn ( KanbanBoardConfigChanged $event ): bool => KanbanBoardConfigChanged::COLUMNS_REORDERED === $event->change );
} );

it( 'refuses a reorder that misses, repeats, or adds a column', function ( Closure $ids ): void {
    $board = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ] ] );
    $other = kanbanBoard( [], [ [ 'processing', 'X' ] ] );

    expect( fn () => app( KanbanColumnService::class )->reorder( $board, $ids( $board, $other ) ) )
        ->toThrow( KanbanOperationException::class );
} )->with( [
    'missing'  => [ fn ( $board ) => [ kanbanColumn( $board, 'A' )->id ] ],
    'repeated' => [ fn ( $board ) => [ kanbanColumn( $board, 'A' )->id, kanbanColumn( $board, 'A' )->id ] ],
    'foreign'  => [ fn ( $board, $other ) => [ kanbanColumn( $board, 'A' )->id, kanbanColumn( $other, 'X' )->id ] ],
] );

it( 'deletes an empty column with its automations', function (): void {
    Event::fake( [ KanbanBoardConfigChanged::class ] );
    $board  = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ] ] );
    $column = kanbanColumn( $board, 'B' );
    KanbanAutomation::factory()->create( [ 'board_id' => $board->id, 'to_column_id' => $column->id ] );

    app( KanbanColumnService::class )->delete( $column );

    expect( KanbanColumn::query()->find( $column->id ) )->toBeNull()
        ->and( KanbanAutomation::query()->where( 'to_column_id', $column->id )->exists() )->toBeFalse();
    Event::assertDispatched( KanbanBoardConfigChanged::class, fn ( KanbanBoardConfigChanged $event ): bool => KanbanBoardConfigChanged::COLUMN_DELETED === $event->change );
} );

it( 'refuses to delete a column with cards, with the card count', function (): void {
    $board  = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ] ] );
    $column = kanbanColumn( $board, 'B' );
    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), $column );
    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), $column );

    try {
        app( KanbanColumnService::class )->delete( $column );
        $this->fail( 'Expected the delete to be refused.' );
    } catch ( KanbanColumnNotEmptyException $e ) {
        expect( $e->cardCount )->toBe( 2 )->and( $e->errorCode )->toBe( 'column-not-empty' );
    }

    expect( KanbanColumn::query()->find( $column->id ) )->not->toBeNull();
} );

it( 'moves the cards to a named column, then deletes', function (): void {
    $board = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ] ] );
    $from  = kanbanColumn( $board, 'B' );
    $to    = kanbanColumn( $board, 'A' );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, $from );

    app( KanbanColumnService::class )->delete( $from, $to, 3 );

    expect( KanbanColumn::query()->find( $from->id ) )->toBeNull()
        ->and( OrderBoardAssignment::query()->where( 'order_id', $order->id )->value( 'substatus_id' ) )->toBe( $to->substatus_id );
} );

it( 'rolls the delete back when a card move is refused', function (): void {
    $board = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ] ] );
    $from  = kanbanColumn( $board, 'B' );
    $to    = kanbanColumn( $board, 'A' );
    $to->update( [ 'wip_limit' => 1 ] );
    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), $to );
    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), $from );

    expect( fn () => app( KanbanColumnService::class )->delete( $from, $to->fresh() ) )->toThrow( KanbanOperationException::class );
    expect( KanbanColumn::query()->find( $from->id ) )->not->toBeNull()
        ->and( $from->cardCount() )->toBe( 1 );
} );

it( 'refuses to move cards to the column itself or another board', function (): void {
    $board = kanbanBoard( [], [ [ 'processing', 'A' ] ] );
    $other = kanbanBoard( [], [ [ 'processing', 'X' ] ] );
    $from  = kanbanColumn( $board, 'A' );
    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), $from );

    expect( fn () => app( KanbanColumnService::class )->delete( $from, $from ) )->toThrow( KanbanOperationException::class )
        ->and( fn () => app( KanbanColumnService::class )->delete( $from, kanbanColumn( $other, 'X' ) ) )->toThrow( KanbanOperationException::class );
} );

it( 'reorders columns over REST and moves cards on delete', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );
    $board = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ] ] );
    $a     = kanbanColumn( $board, 'A' );
    $b     = kanbanColumn( $board, 'B' );

    $this->putJson( "/api/ecommerce/v1/kanban/boards/{$board->id}/columns/order", [ 'column_ids' => [ $b->id, $a->id ] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.0.id', $b->id );

    $this->putJson( "/api/ecommerce/v1/kanban/boards/{$board->id}/columns/order", [ 'column_ids' => [ $b->id ] ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/invalid-column-order' );

    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), $b );

    $this->deleteJson( "/api/ecommerce/v1/kanban/columns/{$b->id}", [], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/column-not-empty' );

    $this->deleteJson( "/api/ecommerce/v1/kanban/columns/{$b->id}", [ 'move_cards_to' => $a->id ], idem() )->assertOk();

    expect( $a->cardCount() )->toBe( 1 );
} );

it( 'refuses to move more cards than the delete cap', function (): void {
    $board = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ] ] );
    $from  = kanbanColumn( $board, 'B' );
    $count = KanbanColumnService::MAX_CARDS_MOVED_ON_DELETE + 1;

    OrderBoardAssignment::factory()->count( $count )->sequence( fn () => [ 'order_id' => kanbanOrder( [ 'system_status' => 'processing' ], [] )->id ] )->create( [
        'board_id'     => $board->id,
        'substatus_id' => $from->substatus_id,
        'assigned_at'  => now(),
        'moved_at'     => now(),
    ] );

    try {
        app( KanbanColumnService::class )->delete( $from, kanbanColumn( $board, 'A' ) );
        $this->fail( 'Expected the delete to be refused.' );
    } catch ( KanbanOperationException $e ) {
        expect( $e->errorCode )->toBe( 'too-many-cards' );
    }
} );

it( 'guards the new REST routes and needs kanbanCard.move to move cards on delete', function (): void {
    $board = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ] ] );
    $a     = kanbanColumn( $board, 'A' );
    $b     = kanbanColumn( $board, 'B' );
    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), $b );

    $this->actingAs( ecommerceShopper(), 'sanctum' );
    $this->putJson( "/api/ecommerce/v1/kanban/boards/{$board->id}/columns/order", [ 'column_ids' => [ $b->id, $a->id ] ], idem() )->assertForbidden();
    $this->putJson( '/api/ecommerce/v1/kanban/widgets/total', [ 'label' => 'X' ], idem() )->assertForbidden();
    $this->deleteJson( '/api/ecommerce/v1/kanban/widgets/total', [], idem() )->assertForbidden();

    Gate::define( 'ecommerce.kanbanBoard.update', fn (): bool => true );
    Gate::define( 'ecommerce.kanbanCard.move', fn (): bool => false );

    $this->deleteJson( "/api/ecommerce/v1/kanban/columns/{$b->id}", [ 'move_cards_to' => $a->id ], idem() )->assertForbidden();
    expect( KanbanColumn::query()->find( $b->id ) )->not->toBeNull();
} );

it( 'announces a board update over REST', function (): void {
    Event::fake( [ KanbanBoardConfigChanged::class ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );
    $board = kanbanBoard();

    $this->patchJson( "/api/ecommerce/v1/kanban/boards/{$board->id}", [ 'name' => 'Renamed' ], idem() )->assertOk();
    $this->patchJson( "/api/ecommerce/v1/kanban/boards/{$board->id}", [ 'name' => 'Renamed' ], idem() )->assertOk();

    Event::assertDispatchedTimes( KanbanBoardConfigChanged::class, 1 );
} );
