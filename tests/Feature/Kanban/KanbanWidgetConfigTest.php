<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\KanbanCardWidget as KanbanCardWidgetContract;
use ArtisanPackUI\Ecommerce\Events\KanbanBoardConfigChanged;
use ArtisanPackUI\Ecommerce\Kanban\KanbanCardRenderer;
use ArtisanPackUI\Ecommerce\Kanban\KanbanWidgetList;
use ArtisanPackUI\Ecommerce\Kanban\Widgets\AbstractKanbanCardWidget;
use ArtisanPackUI\Ecommerce\Models\KanbanCardWidget;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use ArtisanPackUI\Ecommerce\Services\KanbanCardWidgetService;
use ArtisanPackUI\Ecommerce\Services\KanbanColumnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

require_once __DIR__ . '/KanbanTestHelpers.php';
require_once __DIR__ . '/../Api/ApiTestHelpers.php';

uses( RefreshDatabase::class );

/**
 * The rendered payload for `$key` on `$order`'s card in `$column`.
 *
 * @return array<string, mixed>|null
 */
function widgetPayload( Order $order, KanbanColumn $column, string $key ): ?array
{
    return collect( app( KanbanCardRenderer::class )->render( $order, $column->fresh( [ 'board' ] ) ) )->firstWhere( 'key', $key );
}

/**
 * A card that has sat in `$column` for `$days` days.
 */
function staleCard( KanbanColumn $column, int $days ): Order
{
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, $column );
    OrderBoardAssignment::query()->where( 'order_id', $order->id )->update( [ 'moved_at' => Carbon::now()->subDays( $days ) ] );

    return $order->fresh();
}

it( 'normalizes keys and { key, config } entries, keeping the first of a repeated key', function (): void {
    expect( KanbanWidgetList::normalize( [ 'total', [ 'key' => 'tags', 'config' => [ 'max_tags' => 2 ] ], 'total', 7, [ 'config' => [] ] ] ) )->toBe( [
        [ 'key' => 'total', 'config' => [] ],
        [ 'key' => 'tags', 'config' => [ 'max_tags' => 2 ] ],
    ] )->and( KanbanWidgetList::keys( 'nope' ) )->toBe( [] );
} );

it( 'keeps rendering legacy string lists', function (): void {
    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = kanbanColumn( $board, 'Printing' );
    $column->update( [ 'card_widgets' => [ 'total', 'item-count' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, $column );

    expect( app( KanbanCardRenderer::class )->widgetKeys( $column->fresh() ) )->toBe( [ 'total', 'item-count' ] )
        ->and( widgetPayload( $order, $column, 'total' ) )->not->toBeNull();
} );

it( 'passes per-column config to days-in-column', function (): void {
    $board    = kanbanBoard( [], [ [ 'processing', 'Printing' ], [ 'processing', 'Awaiting label' ] ] );
    $printing = kanbanColumn( $board, 'Printing' );
    $label    = kanbanColumn( $board, 'Awaiting label' );
    $printing->update( [ 'card_widgets' => [ [ 'key' => 'days-in-column', 'config' => [ 'stale_after_days' => 1 ] ] ] ] );
    $label->update( [ 'card_widgets' => [ 'total', [ 'key' => 'days-in-column', 'config' => [ 'stale_after_days' => 3 ] ] ] ] );

    expect( widgetPayload( staleCard( $printing, 1 ), $printing, 'days-in-column' )['tone'] )->toBe( 'warning' )
        ->and( widgetPayload( staleCard( $label, 1 ), $label, 'days-in-column' )['tone'] )->toBe( 'neutral' )
        ->and( widgetPayload( staleCard( $label, 6 ), $label, 'days-in-column' )['tone'] )->toBe( 'danger' );
} );

it( 'merges schema defaults, the store override, then the instance config', function (): void {
    $widget = new class () extends AbstractKanbanCardWidget {
        public function key(): string
        {
            return 'merge-probe';
        }

        public function label(): string
        {
            return 'Probe';
        }

        public function configSchema(): array
        {
            return [
                [ 'name' => 'a', 'type' => 'text', 'label' => 'A', 'default' => 'schema' ],
                [ 'name' => 'b', 'type' => 'text', 'label' => 'B', 'default' => 'schema' ],
                [ 'name' => 'c', 'type' => 'text', 'label' => 'C', 'default' => 'schema' ],
            ];
        }

        public function render( Order $order, KanbanColumn $column ): array
        {
            return $this->payload( $this->config( 'a' ) . '/' . $this->config( 'b' ) . '/' . $this->config( 'c' ) );
        }
    };
    app( KanbanCardWidgetRegistry::class )->register( 'merge-probe', $widget );
    app( KanbanCardWidgetService::class )->saveOverride( 'merge-probe', [ 'default_config' => [ 'b' => 'store', 'c' => 'store' ] ] );

    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = kanbanColumn( $board, 'Printing' );
    $column->update( [ 'card_widgets' => [ [ 'key' => 'merge-probe', 'config' => [ 'c' => 'instance' ] ] ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, $column );

    expect( widgetPayload( $order, $column, 'merge-probe' )['value'] )->toBe( 'schema/store/instance' );
} );

it( 'still renders 1.x widgets that only implement render()', function (): void {
    app( KanbanCardWidgetRegistry::class )->register( 'legacy', new class () implements KanbanCardWidgetContract {
        public function key(): string
        {
            return 'legacy';
        }

        public function label(): string
        {
            return 'Legacy';
        }

        public function render( Order $order, KanbanColumn $column ): array
        {
            return [ 'label' => 'Legacy', 'value' => 'ok' ];
        }

        public function refreshSubscription( Order $order ): ?string
        {
            return null;
        }
    } );

    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = kanbanColumn( $board, 'Printing' );
    $column->update( [ 'card_widgets' => [ [ 'key' => 'legacy', 'config' => [] ] ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, $column );

    expect( widgetPayload( $order, $column, 'legacy' )['value'] )->toBe( 'ok' );
} );

it( 'caps tags with max_tags', function (): void {
    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = kanbanColumn( $board, 'Printing' );
    $column->update( [ 'card_widgets' => [ [ 'key' => 'tags', 'config' => [ 'max_tags' => 2 ] ] ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing', 'meta' => [ 'tags' => [ 'rush', 'gift', 'vip' ] ] ] );
    putOnBoard( $order, $column );

    $payload = widgetPayload( $order, $column, 'tags' );

    expect( $payload['value'] )->toBe( 'rush, gift +1' )->and( $payload['tooltip'] )->toBe( 'rush, gift, vip' );
} );

it( 'validates config against the widget schema and rejects unknown keys', function ( array $entries, string $error ): void {
    try {
        KanbanWidgetList::validate( $entries );
        $this->fail( 'Expected a validation error.' );
    } catch ( ValidationException $e ) {
        expect( $e->errors() )->toHaveKey( $error );
    }
} )->with( [
    'unknown widget'     => [ [ [ 'key' => 'nope' ] ], 'card_widgets.0.key' ],
    'not a number'       => [ [ [ 'key' => 'days-in-column', 'config' => [ 'stale_after_days' => 'soon' ] ] ], 'card_widgets.0.config.stale_after_days' ],
    'below the minimum'  => [ [ [ 'key' => 'tags', 'config' => [ 'max_tags' => 0 ] ] ], 'card_widgets.0.config.max_tags' ],
    'undeclared setting' => [ [ [ 'key' => 'tags', 'config' => [ 'color' => 'red' ] ] ], 'card_widgets.0.config' ],
    'extra entry key'    => [ [ [ 'key' => 'tags', 'extra' => true ] ], 'card_widgets.0' ],
    'not a key'          => [ [ 7 ], 'card_widgets.0' ],
] );

it( 'writes mixed lists through the column service', function (): void {
    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = kanbanColumn( $board, 'Printing' );

    $updated = app( KanbanColumnService::class )->update( $column, [ 'card_widgets' => [ 'total', [ 'key' => 'days-in-column', 'config' => [ 'stale_after_days' => 1 ] ] ] ] );

    expect( $updated->card_widgets )->toBe( [ 'total', [ 'key' => 'days-in-column', 'config' => [ 'stale_after_days' => 1 ] ] ] );
} );

it( 'sets a board default list and says which list a column uses', function (): void {
    Event::fake( [ KanbanBoardConfigChanged::class ] );
    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = kanbanColumn( $board, 'Printing' );

    expect( app( KanbanCardRenderer::class )->widgetSource( $column ) )->toBe( 'default' );

    app( KanbanCardWidgetService::class )->setBoardWidgets( $board, [ 'shipping-method' ] );

    expect( app( KanbanCardRenderer::class )->widgetSource( $column->fresh( 'board' ) ) )->toBe( 'board' )
        ->and( app( KanbanCardRenderer::class )->widgetKeys( $column->fresh( 'board' ) ) )->toBe( [ 'shipping-method' ] );
    Event::assertDispatched( KanbanBoardConfigChanged::class );

    $column->update( [ 'card_widgets' => [ 'total' ] ] );

    expect( app( KanbanCardRenderer::class )->widgetSource( $column->fresh( 'board' ) ) )->toBe( 'column' );
} );

it( 'stores, applies, and resets a store-wide label and default config', function (): void {
    $service = app( KanbanCardWidgetService::class );
    $service->saveOverride( 'total', [ 'label' => 'Order value' ] );

    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = kanbanColumn( $board, 'Printing' );
    $column->update( [ 'card_widgets' => [ 'total' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, $column );

    expect( widgetPayload( $order, $column, 'total' )['label'] )->toBe( 'Order value' );

    expect( fn () => $service->saveOverride( 'days-in-column', [ 'default_config' => [ 'color' => 'red' ] ] ) )->toThrow( ValidationException::class )
        ->and( fn () => $service->saveOverride( 'nope', [ 'label' => 'X' ] ) )->toThrow( ValidationException::class );

    expect( $service->resetOverride( 'total' ) )->toBeTrue()
        ->and( KanbanCardWidget::query()->where( 'key', 'total' )->exists() )->toBeFalse()
        ->and( widgetPayload( $order, $column, 'total' )['label'] )->not->toBe( 'Order value' );
} );

it( 'exposes config schemas and overrides over REST', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->putJson( '/api/ecommerce/v1/kanban/widgets/days-in-column', [ 'label' => 'Age', 'default_config' => [ 'stale_after_days' => 2 ] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.label', 'Age' )
        ->assertJsonPath( 'data.default_config.stale_after_days', 2 );

    $this->putJson( '/api/ecommerce/v1/kanban/widgets/days-in-column', [ 'default_config' => [ 'stale_after_days' => 'x' ] ], idem() )->assertStatus( 422 );
    $this->putJson( '/api/ecommerce/v1/kanban/widgets/nope', [ 'label' => 'X' ], idem() )->assertNotFound();

    $row = collect( $this->getJson( '/api/ecommerce/v1/kanban/widgets' )->assertOk()->json( 'data' ) )->firstWhere( 'key', 'days-in-column' );

    expect( $row['label'] )->toBe( 'Age' )
        ->and( collect( $row['config_schema'] )->pluck( 'name' )->all() )->toBe( [ 'stale_after_days' ] );

    $this->deleteJson( '/api/ecommerce/v1/kanban/widgets/days-in-column', [], idem() )->assertOk()->assertJsonPath( 'data.label', 'Days in column' );

    $board = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $this->postJson( "/api/ecommerce/v1/kanban/boards/{$board->id}/columns", [
        'substatus_id' => OrderSubstatus::factory()->forSystemStatus( 'processing' )->create()->id,
        'card_widgets' => [ [ 'key' => 'tags', 'config' => [ 'max_tags' => 'many' ] ] ],
    ], idem() )->assertStatus( 422 );
} );

it( 'caps the list size and the config size', function (): void {
    $tooMany   = array_fill( 0, KanbanWidgetList::MAX_ENTRIES + 1, 'total' );
    $bigConfig = array_fill_keys( array_map( fn ( int $i ): string => 'k' . $i, range( 0, KanbanWidgetList::MAX_CONFIG_KEYS ) ), 1 );

    expect( fn () => KanbanWidgetList::validate( $tooMany ) )->toThrow( ValidationException::class )
        ->and( fn () => KanbanWidgetList::validate( [ [ 'key' => 'total', 'config' => $bigConfig ] ] ) )->toThrow( ValidationException::class );
} );

it( 'keeps card_widgets as keys in the column resource and adds the entries', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );
    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = kanbanColumn( $board, 'Printing' );
    $column->update( [ 'card_widgets' => [ 'total', [ 'key' => 'tags', 'config' => [ 'max_tags' => 2 ] ] ] ] );

    $this->patchJson( "/api/ecommerce/v1/kanban/columns/{$column->id}", [ 'label_override' => 'Print' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.card_widgets', [ 'total', 'tags' ] )
        ->assertJsonPath( 'data.card_widget_entries.1', [ 'key' => 'tags', 'config' => [ 'max_tags' => 2 ] ] );
} );

it( 'tells active boards when a store-wide override changes', function (): void {
    $active = kanbanBoard();
    kanbanBoard( [ 'is_active' => false ] );
    Event::fake( [ KanbanBoardConfigChanged::class ] );

    app( KanbanCardWidgetService::class )->saveOverride( 'total', [ 'label' => 'Order value' ] );
    app( KanbanCardWidgetService::class )->resetOverride( 'total' );

    Event::assertDispatchedTimes( KanbanBoardConfigChanged::class, 2 );
    Event::assertDispatched( KanbanBoardConfigChanged::class, fn ( KanbanBoardConfigChanged $event ): bool => $event->board->is( $active ) );
} );

it( 'stores no label for a config-only override, so the widget keeps its own translated label', function (): void {
    $service = app( KanbanCardWidgetService::class );
    $row     = $service->saveOverride( 'days-in-column', [ 'default_config' => [ 'stale_after_days' => 2 ] ] );

    expect( $row->label )->toBeNull();

    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = kanbanColumn( $board, 'Printing' );
    $column->update( [ 'card_widgets' => [ 'days-in-column' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, $column );

    app()->setLocale( 'de' );
    app( KanbanCardRenderer::class )->forgetOverrides();

    expect( widgetPayload( $order, $column, 'days-in-column' )['label'] )->toBe( __( 'Days in column' ) );

    app()->setLocale( 'en' );
} );

it( 'keeps a stored label when the label is left out, and clears it when it is emptied', function (): void {
    $service = app( KanbanCardWidgetService::class );

    $service->saveOverride( 'total', [ 'label' => 'Order value' ] );
    $service->saveOverride( 'total', [ 'default_config' => [] ] );

    expect( KanbanCardWidget::query()->where( 'key', 'total' )->value( 'label' ) )->toBe( 'Order value' );

    $service->saveOverride( 'total', [ 'label' => '  ' ] );

    expect( KanbanCardWidget::query()->where( 'key', 'total' )->value( 'label' ) )->toBeNull();
} );

it( 'shows the widget\'s own label over REST when no label override is stored', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->putJson( '/api/ecommerce/v1/kanban/widgets/days-in-column', [ 'default_config' => [ 'stale_after_days' => 2 ] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.label', 'Days in column' )
        ->assertJsonPath( 'data.label_override', null );

    $this->putJson( '/api/ecommerce/v1/kanban/widgets/days-in-column', [ 'label' => 'Age' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.label', 'Age' )
        ->assertJsonPath( 'data.label_override', 'Age' );
} );
