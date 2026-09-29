<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanCardWidget;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/ApiTestHelpers.php';
require_once __DIR__ . '/../Kanban/KanbanTestHelpers.php';

uses( RefreshDatabase::class );

const KANBAN_API = '/api/ecommerce/v1/kanban';

it( 'requires authentication and the kanban abilities', function (): void {
    $this->getJson( KANBAN_API . '/boards' )->assertUnauthorized();

    $this->actingAs( ecommerceShopper(), 'sanctum' )->getJson( KANBAN_API . '/boards' )->assertForbidden();

    // A user who can manage boards can't move cards without kanbanCard.move.
    Gate::define( 'ecommerce.kanbanBoard.viewAny', fn (): bool => true );
    Gate::define( 'ecommerce.kanbanCard.move', fn (): bool => false );

    $board = kanbanBoard();
    $order = kanbanOrder();

    $this->actingAs( ecommerceShopper(), 'sanctum' )->getJson( KANBAN_API . '/boards' )->assertOk();
    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->postJson( KANBAN_API . "/cards/{$order->id}/move", [ 'to_column_id' => $board->columns->first()->id ], idem() )
        ->assertForbidden();
} );

it( 'creates, lists, updates, and deletes boards', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->postJson( KANBAN_API . '/boards', [ 'name' => 'Production' ] )->assertStatus( 400 );

    $id = $this->postJson( KANBAN_API . '/boards', [
        'key'           => 'production',
        'name'          => 'Production',
        'routing_rules' => [ 'any' => [ [ 'type' => 'cart-contains-product-type', 'config' => [ 'types' => [ 'simple' ] ] ] ] ],
        'settings'      => [ 'card_widgets' => [ 'total', 'days-in-column' ] ],
        'position'      => 2,
    ], idem() )->assertCreated()->assertJsonPath( 'data.type', 'kanbanBoard' )->json( 'data.id' );

    kanbanBoard( [ 'key' => 'shipping', 'position' => 1 ] );

    $this->getJson( KANBAN_API . '/boards' )
        ->assertOk()
        ->assertJsonPath( 'data.0.key', 'shipping' )
        ->assertJsonPath( 'data.1.key', 'production' );

    $this->patchJson( KANBAN_API . "/boards/{$id}", [ 'is_active' => false ], idem() )->assertOk()->assertJsonPath( 'data.is_active', false );
    $this->getJson( KANBAN_API . '/boards?filter[is_active]=true' )->assertJsonCount( 1, 'data' );

    $this->deleteJson( KANBAN_API . "/boards/{$id}", [], idem() )->assertOk();
    expect( KanbanBoard::query()->whereKey( $id )->exists() )->toBeFalse();
} );

it( 'validates board keys, routing rules, and widget keys', function (): void {
    kanbanBoard( [ 'key' => 'taken' ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $errors = fn ( array $payload ): array => collect( $this->postJson( KANBAN_API . '/boards', $payload, idem() )->assertStatus( 422 )->json( 'errors' ) )->pluck( 'field' )->all();

    expect( $errors( [ 'key' => 'taken', 'name' => 'x' ] ) )->toContain( 'key' )
        ->and( $errors( [ 'key' => 'Bad Key', 'name' => 'x' ] ) )->toContain( 'key' )
        ->and( $errors( [ 'key' => 'ok', 'name' => 'x', 'routing_rules' => [ 'type' => 'no-such-condition' ] ] ) )->toContain( 'routing_rules' )
        ->and( $errors( [ 'key' => 'ok', 'name' => 'x', 'settings' => [ 'card_widgets' => [ 'nope' ] ] ] ) )->toContain( 'settings.card_widgets.0' );
} );

it( 'shows a board with its columns, card counts, and WIP state', function (): void {
    $board = kanbanBoard( [], [ [ 'processing', 'Printing' ], [ 'complete', 'Done', true ] ] );
    $print = kanbanColumn( $board, 'Printing' );
    $print->update( [ 'wip_limit' => 1, 'color_override' => '#112233' ] );

    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), $print );
    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), $print );
    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), $print )->update( [ 'removed_at' => now() ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( KANBAN_API . "/boards/{$board->id}?include=automations" )
        ->assertOk()
        ->assertJsonCount( 2, 'data.columns' )
        ->assertJsonPath( 'data.columns.0.label', 'Printing' )
        ->assertJsonPath( 'data.columns.0.system_status', 'processing' )
        ->assertJsonPath( 'data.columns.0.color', '#112233' )
        ->assertJsonPath( 'data.columns.0.card_count', 2 )
        ->assertJsonPath( 'data.columns.0.is_over_wip_limit', true )
        ->assertJsonPath( 'data.columns.1.card_count', 0 )
        ->assertJsonPath( 'data.automations', [] );
} );

it( 'manages columns and refuses to delete or re-point a column that has cards', function (): void {
    $board     = kanbanBoard( [], [] );
    $substatus = OrderSubstatus::factory()->forSystemStatus( 'processing' )->create( [ 'label' => 'Printing' ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $columnId = $this->postJson( KANBAN_API . "/boards/{$board->id}/columns", [ 'substatus_id' => $substatus->id, 'wip_limit' => 5, 'card_widgets' => [ 'total' ] ], idem() )
        ->assertCreated()
        ->assertJsonPath( 'data.label', 'Printing' )
        ->assertJsonPath( 'data.card_widgets', [ 'total' ] )
        ->json( 'data.id' );

    $this->postJson( KANBAN_API . "/boards/{$board->id}/columns", [ 'substatus_id' => $substatus->id ], idem() )->assertStatus( 422 );
    $this->postJson( KANBAN_API . "/boards/{$board->id}/columns", [ 'substatus_id' => $substatus->id + 1_000 ], idem() )->assertStatus( 422 );

    $this->patchJson( KANBAN_API . "/columns/{$columnId}", [ 'label_override' => 'Print queue', 'position' => 3, 'card_widgets' => [ 'total', 'tags' ] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.label', 'Print queue' )
        ->assertJsonPath( 'data.position', 3 )
        ->assertJsonPath( 'data.card_widgets', [ 'total', 'tags' ] );

    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), KanbanColumn::query()->find( $columnId ) );

    $other = OrderSubstatus::factory()->forSystemStatus( 'processing' )->create();

    $this->patchJson( KANBAN_API . "/columns/{$columnId}", [ 'substatus_id' => $other->id ], idem() )->assertStatus( 422 );
    $this->deleteJson( KANBAN_API . "/columns/{$columnId}", [], idem() )->assertStatus( 422 )->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/column-not-empty' );

    OrderBoardAssignment::query()->delete();

    $this->deleteJson( KANBAN_API . "/columns/{$columnId}", [], idem() )->assertOk();
} );

it( 'manages automations without echoing their secrets', function (): void {
    $board = kanbanBoard( [], [ [ 'processing', 'Printing' ], [ 'complete', 'Shipped', true ] ] );
    $other = kanbanBoard( [], [ [ 'processing', 'Elsewhere' ] ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $payload = [
        'to_column_id'   => kanbanColumn( $board, 'Shipped' )->id,
        'trigger_key'    => 'webhook',
        'trigger_config' => [ 'url' => 'https://hooks.example.test/shipped', 'secret' => 'shh' ],
        'conditions'     => [ 'type' => 'min-subtotal', 'config' => [ 'amount' => 100 ] ],
    ];

    $id = $this->postJson( KANBAN_API . "/boards/{$board->id}/automations", $payload, idem() )
        ->assertCreated()
        ->assertJsonPath( 'data.has_secret', true )
        ->assertJsonMissingPath( 'data.trigger_config.secret' )
        ->json( 'data.id' );

    $this->patchJson( KANBAN_API . "/automations/{$id}", [ 'trigger_config' => [ 'url' => 'https://hooks.example.test/v2' ] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.trigger_config.url', 'https://hooks.example.test/v2' );

    expect( KanbanAutomation::query()->find( $id )->trigger_config['secret'] )->toBe( 'shh' );

    $this->postJson( KANBAN_API . "/boards/{$board->id}/automations", [ ...$payload, 'trigger_key' => 'nope' ], idem() )->assertStatus( 422 );
    $this->postJson( KANBAN_API . "/boards/{$board->id}/automations", [ ...$payload, 'to_column_id' => kanbanColumn( $other, 'Elsewhere' )->id ], idem() )->assertStatus( 422 );
    $this->postJson( KANBAN_API . "/boards/{$board->id}/automations", [ ...$payload, 'conditions' => [ 'all' => 'x' ] ], idem() )->assertStatus( 422 );

    $this->deleteJson( KANBAN_API . "/automations/{$id}", [], idem() )->assertOk();
    expect( KanbanAutomation::query()->count() )->toBe( 0 );
} );

it( 'lists cards with rendered widgets, paginated and filterable by column', function (): void {
    $board = kanbanBoard( [], [ [ 'processing', 'Printing' ], [ 'processing', 'Packing' ] ] );
    kanbanColumn( $board, 'Printing' )->update( [ 'card_widgets' => [ 'total', 'item-count' ] ] );

    foreach ( range( 1, 3 ) as $i ) {
        putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), kanbanColumn( $board, 'Printing' ) );
    }

    putOnBoard( kanbanOrder( [ 'system_status' => 'processing' ] ), kanbanColumn( $board, 'Packing' ) );

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $page = $this->getJson( KANBAN_API . "/boards/{$board->id}/cards?per_page=2" )
        ->assertOk()
        ->assertJsonCount( 2, 'data' )
        ->assertJsonPath( 'data.0.type', 'kanbanCard' )
        ->assertJsonStructure( [ 'data' => [ [ 'order_id', 'column_id', 'widgets', 'order' => [ 'order_number' ] ] ], 'links', 'meta' ] );

    expect( $page->json( 'meta.next_cursor' ) )->not->toBeNull();

    $printing = kanbanColumn( $board, 'Printing' );

    $this->getJson( KANBAN_API . "/boards/{$board->id}/cards?filter[column_id]={$printing->id}" )
        ->assertOk()
        ->assertJsonCount( 3, 'data' )
        ->assertJsonPath( 'data.0.column_id', $printing->id )
        ->assertJsonPath( 'data.0.widgets.0.key', 'total' )
        ->assertJsonPath( 'data.0.widgets.1.value', '1 item' );
} );

it( 'moves a card through the API and maps refusals to 422 problems', function (): void {
    $board = kanbanBoard( [], [ [ 'pending', 'New' ], [ 'processing', 'Working' ], [ 'complete', 'Done', true ] ] );
    $order = kanbanOrder( [ 'system_status' => 'pending' ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->postJson( KANBAN_API . "/cards/{$order->id}/move", [ 'to_column_id' => kanbanColumn( $board, 'Working' )->id ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/not-on-board' );

    putOnBoard( $order, kanbanColumn( $board, 'New' ) );

    $this->postJson( KANBAN_API . "/cards/{$order->id}/move", [ 'to_column_id' => kanbanColumn( $board, 'Done' )->id ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/invalid-status-transition' );

    $this->postJson( KANBAN_API . "/cards/{$order->id}/move", [ 'to_column_id' => kanbanColumn( $board, 'Working' )->id, 'reason' => 'started' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.column_id', kanbanColumn( $board, 'Working' )->id )
        ->assertJsonPath( 'data.order.system_status', 'processing' );

    $this->postJson( KANBAN_API . "/cards/{$order->id}/move", [], idem() )->assertStatus( 422 );
} );

it( 'adds and removes board assignments', function (): void {
    $board = kanbanBoard();
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->postJson( KANBAN_API . "/boards/{$board->id}/assignments/{$order->id}", [ 'column_id' => kanbanColumn( $board, 'Done' )->id ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.column_id', kanbanColumn( $board, 'Done' )->id );

    $this->deleteJson( KANBAN_API . "/boards/{$board->id}/assignments/{$order->id}", [], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.removed_at', fn ( ?string $value ): bool => null !== $value );

    $this->deleteJson( KANBAN_API . "/boards/{$board->id}/assignments/{$order->id}", [], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/not-on-board' );

    $this->postJson( KANBAN_API . "/boards/{$board->id}/assignments/{$order->id}", [], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.column_id', kanbanColumn( $board, 'Working' )->id );
} );

it( 'lists the widget and trigger catalogs, honouring stored widget overrides', function (): void {
    KanbanCardWidget::factory()->create( [ 'key' => 'total', 'label' => 'Order total', 'default_config' => [ 'show_refunds' => true ] ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $widgets = collect( $this->getJson( KANBAN_API . '/widgets' )->assertOk()->json( 'data' ) )->keyBy( 'key' );

    expect( $widgets->keys()->all() )->toContain( 'total', 'days-in-column' )
        ->and( $widgets['total']['label'] )->toBe( 'Order total' )
        ->and( $widgets['total']['default_config'] )->toBe( [ 'show_refunds' => true ] )
        ->and( $widgets['tags']['provided_by'] )->toBe( 'ecommerce' );

    $this->getJson( KANBAN_API . '/triggers' )
        ->assertOk()
        ->assertJsonPath( 'data.0.key', 'send-email' )
        ->assertJsonFragment( [ 'key' => 'print-shipping-label', 'label' => 'Print a shipping label' ] );
} );
