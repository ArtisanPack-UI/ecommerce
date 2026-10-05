<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanCardWidget;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/KanbanTestHelpers.php';

uses( RefreshDatabase::class );

it( 'creates every kanban table from the engine migrations', function (): void {
    foreach ( [ 'ecommerce_kanban_boards', 'ecommerce_kanban_columns', 'ecommerce_kanban_automations', 'ecommerce_kanban_card_widgets', 'ecommerce_order_board_assignments' ] as $table ) {
        expect( Schema::hasTable( $table ) )->toBeTrue( $table );
    }

    expect( Schema::hasColumns( 'ecommerce_order_board_assignments', [ 'order_id', 'board_id', 'substatus_id', 'assigned_at', 'moved_at', 'removed_at' ] ) )->toBeTrue();
} );

it( 'defaults board JSON columns and flags', function (): void {
    $board = KanbanBoard::query()->create( [ 'key' => 'production', 'name' => 'Production' ] )->fresh();

    expect( $board->routing_rules )->toBe( [] )
        ->and( $board->settings )->toBe( [] )
        ->and( $board->is_active )->toBeTrue()
        ->and( $board->is_default )->toBeFalse()
        ->and( $board->hasRoutingRules() )->toBeFalse();

    $board->routing_rules = [ 'type' => 'min-subtotal', 'config' => [ 'amount' => 1 ] ];

    expect( $board->hasRoutingRules() )->toBeTrue();
} );

it( 'orders columns by position and resolves labels from the sub-status', function (): void {
    $board = kanbanBoard( [], [ [ 'processing', 'Printing' ], [ 'processing', 'Design' ] ] );
    $board->columns()->first()->update( [ 'position' => 5 ] );

    expect( $board->fresh()->columns->map->displayLabel()->all() )->toBe( [ 'Design', 'Printing' ] );

    $column                 = $board->fresh()->columns->first();
    $column->label_override = 'Artwork';

    expect( $column->displayLabel() )->toBe( 'Artwork' );
} );

it( 'keeps an order on several boards at once, each with its own column', function (): void {
    $production = kanbanBoard( [ 'key' => 'production' ], [ [ 'processing', 'Printing' ] ] );
    $shipping   = kanbanBoard( [ 'key' => 'shipping' ], [ [ 'processing', 'Awaiting label' ] ] );
    $order      = Order::factory()->create( [ 'system_status' => 'processing' ] );

    putOnBoard( $order, kanbanColumn( $production, 'Printing' ) );
    putOnBoard( $order, kanbanColumn( $shipping, 'Awaiting label' ) );

    $cards = $order->boardAssignments()->with( 'substatus' )->get();

    expect( $cards )->toHaveCount( 2 )
        ->and( $cards->pluck( 'substatus.label' )->sort()->values()->all() )->toBe( [ 'Awaiting label', 'Printing' ] )
        ->and( $cards->first()->currentColumn()?->board_id )->toBe( $cards->first()->board_id );
} );

it( 'allows one assignment per order and board', function (): void {
    $board = kanbanBoard();
    $order = Order::factory()->create();

    putOnBoard( $order, $board->columns->first() );

    expect( fn () => putOnBoard( $order, $board->columns->first() ) )->toThrow( QueryException::class );
} );

it( 'allows one column per sub-status on a board', function (): void {
    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = $board->columns->first();

    expect( fn () => KanbanColumn::factory()->create( [ 'board_id' => $board->id, 'substatus_id' => $column->substatus_id ] ) )
        ->toThrow( QueryException::class );
} );

it( 'scopes active assignments and counts cards per column', function (): void {
    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = $board->columns->first();

    putOnBoard( Order::factory()->create(), $column );
    putOnBoard( Order::factory()->create(), $column )->update( [ 'removed_at' => now() ] );

    expect( OrderBoardAssignment::query()->active()->count() )->toBe( 1 )
        ->and( $column->cardCount() )->toBe( 1 )
        ->and( $board->activeAssignments()->count() )->toBe( 1 )
        ->and( $board->assignments()->count() )->toBe( 2 );
} );

it( 'cascades a board delete to its columns, automations, and cards', function (): void {
    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = $board->columns->first();

    KanbanAutomation::factory()->create( [ 'board_id' => $board->id, 'to_column_id' => $column->id ] );
    putOnBoard( Order::factory()->create(), $column );

    $board->delete();

    expect( KanbanColumn::query()->count() )->toBe( 0 )
        ->and( KanbanAutomation::query()->count() )->toBe( 0 )
        ->and( OrderBoardAssignment::query()->count() )->toBe( 0 );
} );

it( 'links automations to their board and columns', function (): void {
    $board      = kanbanBoard( [], [ [ 'processing', 'Printing' ], [ 'complete', 'Shipped', true ] ] );
    $automation = KanbanAutomation::factory()->create( [
        'board_id'       => $board->id,
        'from_column_id' => kanbanColumn( $board, 'Printing' )->id,
        'to_column_id'   => kanbanColumn( $board, 'Shipped' )->id,
        'conditions'     => [ 'type' => 'min-subtotal', 'config' => [ 'amount' => 100 ] ],
    ] );

    expect( $automation->board->is( $board ) )->toBeTrue()
        ->and( $automation->fromColumn->displayLabel() )->toBe( 'Printing' )
        ->and( $automation->toColumn->displayLabel() )->toBe( 'Shipped' )
        ->and( $automation->conditions['type'] )->toBe( 'min-subtotal' )
        ->and( $board->automations()->count() )->toBe( 1 );
} );

it( 'stores a card widget catalog row with unique keys', function (): void {
    $widget = KanbanCardWidget::factory()->create( [ 'key' => 'printful:order-status', 'provided_by' => 'ecommerce-printful', 'default_config' => [ 'show_eta' => true ] ] );

    expect( $widget->fresh()->default_config )->toBe( [ 'show_eta' => true ] );
    expect( fn () => KanbanCardWidget::factory()->create( [ 'key' => 'printful:order-status' ] ) )->toThrow( QueryException::class );
} );
