<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger;
use ArtisanPackUI\Ecommerce\Events\KanbanAutomationTriggered;
use ArtisanPackUI\Ecommerce\Events\KanbanCardMoved;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Services\KanbanAutomationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../Kanban/KanbanTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->board = kanbanBoard( [ 'key' => 'coverage' ], [ [ 'processing', 'Printing' ], [ 'processing', 'Packing' ], [ 'complete', 'Shipped', true ] ] );
    $this->order = kanbanOrder( [ 'system_status' => 'processing', 'total_amount' => 12_400 ] );
} );

afterEach( function (): void {
    removeAllActions( 'ap.ecommerce.kanban.automationFired' );
} );

/**
 * An automation on `$board` into `$to` that sets `meta.{$flag}`.
 *
 * @param  array<string, mixed>  $attributes
 */
function covAutomation( KanbanBoard $board, string $to, string $flag, array $attributes = [] ): KanbanAutomation
{
    return KanbanAutomation::factory()->create( array_merge( [
        'board_id'       => $board->id,
        'to_column_id'   => kanbanColumn( $board, $to )->id,
        'trigger_key'    => 'update-order-field',
        'trigger_config' => [ 'field' => 'meta.' . $flag, 'value' => true ],
    ], $attributes ) );
}

function covRunner(): KanbanAutomationRunner
{
    return app( KanbanAutomationRunner::class );
}

it( 'fires matching automations, records each on the timeline, and returns them', function (): void {
    Event::fake( [ KanbanAutomationTriggered::class ] );
    $hooks = [];
    addAction( 'ap.ecommerce.kanban.automationFired', function ( KanbanAutomation $automation, Order $order ) use ( &$hooks ): void {
        $hooks[] = $automation->id;
    } );

    $any      = covAutomation( $this->board, 'Packing', 'any' );
    $fromPrnt = covAutomation( $this->board, 'Packing', 'from_printing', [ 'from_column_id' => kanbanColumn( $this->board, 'Printing' )->id ] );
    covAutomation( $this->board, 'Packing', 'from_shipped', [ 'from_column_id' => kanbanColumn( $this->board, 'Shipped' )->id ] );
    covAutomation( $this->board, 'Shipped', 'other_target' );
    covAutomation( $this->board, 'Packing', 'off', [ 'is_active' => false ] );

    $fired = covRunner()->run( $this->order, kanbanColumn( $this->board, 'Printing' ), kanbanColumn( $this->board, 'Packing' ), $this->board );

    expect( array_map( fn ( KanbanAutomation $automation ): int => $automation->id, $fired ) )->toBe( [ $any->id, $fromPrnt->id ] )
        ->and( array_keys( (array) $this->order->fresh()->meta ) )->toEqualCanonicalizing( [ 'any', 'from_printing' ] )
        ->and( $hooks )->toBe( [ $any->id, $fromPrnt->id ] )
        ->and( OrderTimelineEntry::query()->where( 'order_id', $this->order->id )->where( 'event_type', 'kanban.automation_fired' )->pluck( 'payload' )->map( fn ( array $payload ): int => $payload['automation_id'] )->all() )->toBe( [ $any->id, $fromPrnt->id ] );

    Event::assertDispatchedTimes( KanbanAutomationTriggered::class, 2 );
} );

it( 'only runs "from any" automations for a card that wasn\'t on the board', function (): void {
    $any  = covAutomation( $this->board, 'Packing', 'any' );
    $from = covAutomation( $this->board, 'Packing', 'from_printing', [ 'from_column_id' => kanbanColumn( $this->board, 'Printing' )->id ] );

    $fired = covRunner()->run( $this->order, new KanbanColumn(), kanbanColumn( $this->board, 'Packing' ), $this->board );

    expect( array_map( fn ( KanbanAutomation $automation ): int => $automation->id, $fired ) )->toBe( [ $any->id ] )
        ->and( $from->id )->not->toBe( $any->id );
} );

it( 'ignores automations on other boards', function (): void {
    $other = kanbanBoard( [ 'key' => 'other-board' ], [ [ 'processing', 'Packing' ] ] );
    covAutomation( $other, 'Packing', 'elsewhere' );

    expect( covRunner()->run( $this->order, kanbanColumn( $this->board, 'Printing' ), kanbanColumn( $this->board, 'Packing' ), $this->board ) )->toBe( [] );
} );

it( 'skips automations whose conditions the order fails', function (): void {
    covAutomation( $this->board, 'Packing', 'small', [ 'conditions' => [ 'type' => 'min-subtotal', 'config' => [ 'amount' => 10_000_000 ] ] ] );
    $big = covAutomation( $this->board, 'Packing', 'big', [ 'conditions' => [ 'type' => 'min-subtotal', 'config' => [ 'amount' => 100 ] ] ] );

    $fired = covRunner()->run( $this->order, kanbanColumn( $this->board, 'Printing' ), kanbanColumn( $this->board, 'Packing' ), $this->board );

    expect( array_map( fn ( KanbanAutomation $automation ): int => $automation->id, $fired ) )->toBe( [ $big->id ] );
} );

it( 'records a failure on the timeline for an unregistered trigger, and keeps going', function (): void {
    Event::fake( [ KanbanAutomationTriggered::class ] );
    $missing = covAutomation( $this->board, 'Packing', 'unused', [ 'trigger_key' => 'no-such-trigger' ] );
    $ok      = covAutomation( $this->board, 'Packing', 'ok' );

    $fired = covRunner()->run( $this->order, kanbanColumn( $this->board, 'Printing' ), kanbanColumn( $this->board, 'Packing' ), $this->board );

    $failure = OrderTimelineEntry::query()->where( 'order_id', $this->order->id )->where( 'event_type', 'kanban.automation_failed' )->sole();

    expect( array_map( fn ( KanbanAutomation $automation ): int => $automation->id, $fired ) )->toBe( [ $ok->id ] )
        ->and( $failure->payload['automation_id'] )->toBe( $missing->id )
        ->and( $failure->payload['trigger_key'] )->toBe( 'no-such-trigger' )
        ->and( $failure->payload['error'] )->toContain( 'no-such-trigger' );

    Event::assertDispatchedTimes( KanbanAutomationTriggered::class, 1 );
} );

it( 'records a failure when a trigger throws, without failing the move', function (): void {
    app( KanbanAutomationRegistry::class )->register( 'cov-explodes', new class implements KanbanAutomationTrigger {
        public function key(): string
        {
            return 'cov-explodes';
        }

        public function label(): string
        {
            return 'Explodes';
        }

        public function fire( Order $order, KanbanAutomation $automation, array $config ): void
        {
            throw new RuntimeException( 'Printer on fire' );
        }
    } );

    covAutomation( $this->board, 'Packing', 'unused', [ 'trigger_key' => 'cov-explodes', 'trigger_config' => [] ] );

    expect( covRunner()->run( $this->order, kanbanColumn( $this->board, 'Printing' ), kanbanColumn( $this->board, 'Packing' ), $this->board ) )->toBe( [] )
        ->and( OrderTimelineEntry::query()->where( 'event_type', 'kanban.automation_failed' )->sole()->payload['error'] )->toBe( 'Printer on fire' )
        ->and( OrderTimelineEntry::query()->where( 'event_type', 'kanban.automation_fired' )->exists() )->toBeFalse();
} );

it( 'runs from the KanbanCardMoved event', function (): void {
    covAutomation( $this->board, 'Packing', 'via_event' );

    covRunner()->handle( new KanbanCardMoved( $this->order, kanbanColumn( $this->board, 'Printing' ), kanbanColumn( $this->board, 'Packing' ), $this->board ) );

    expect( (array) $this->order->fresh()->meta )->toHaveKey( 'via_event' );
} );
