<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Broadcasting\KanbanBoardBroadcast;
use ArtisanPackUI\Ecommerce\Kanban\KanbanCardPlacement;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use ArtisanPackUI\Ecommerce\Services\KanbanBoardService;
use ArtisanPackUI\Ecommerce\Services\KanbanColumnService;
use ArtisanPackUI\Ecommerce\Services\KanbanRoutingService;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

require_once __DIR__ . '/KanbanTestHelpers.php';

uses( RefreshDatabase::class );

/**
 * Turns board broadcasts on the way a host app would (config before boot).
 */
function enableBoardEvents(): void
{
    config()->set( 'artisanpack.ecommerce.kanban.broadcast', true );
    config()->set( 'artisanpack.ecommerce.kanban.auto_route', false );

    ( fn () => $this->registerKanbanListeners() )->call( new EcommerceServiceProvider( app() ) );
}

/**
 * The board events broadcast so far, in order.
 *
 * @return array<int, array<string, mixed>>
 */
function boardEvents( string $type ): array
{
    return Event::dispatched( KanbanBoardBroadcast::class )
        ->map( fn ( array $args ) => $args[0]->broadcastWith() )
        ->filter( fn ( array $payload ): bool => $type === $payload['type'] )
        ->values()
        ->all();
}

it( 'broadcasts nothing while broadcasting is off', function (): void {
    Event::fake( [ KanbanBoardBroadcast::class ] );
    $board = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, kanbanColumn( $board, 'A' ) );

    app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'B' ) );

    Event::assertNotDispatched( KanbanBoardBroadcast::class );
} );

it( 'broadcasts a move with columns, position, and the actor on the board channel', function (): void {
    enableBoardEvents();
    Event::fake( [ KanbanBoardBroadcast::class ] );

    $board = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, kanbanColumn( $board, 'A' ) );

    app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'B' ), 42 );

    [ $event ] = boardEvents( 'card-moved' );

    expect( $event )->toMatchArray( [
        'board_id'       => $board->id,
        'order_id'       => $order->id,
        'from_column_id' => kanbanColumn( $board, 'A' )->id,
        'to_column_id'   => kanbanColumn( $board, 'B' )->id,
        'column_id'      => kanbanColumn( $board, 'B' )->id,
        'actor_user_id'  => 42,
    ] )->and( $event['position'] )->toBeInt()->and( $event['occurred_at'] )->toBeString();

    Event::assertDispatched( KanbanBoardBroadcast::class, fn ( KanbanBoardBroadcast $broadcast ): bool => 'kanbanBoardEvent' === $broadcast->broadcastAs()
        && 'private-ecommerce.kanban.board.' . $board->id === $broadcast->broadcastOn()[0]->name );
} );

it( 'broadcasts a same-column reorder', function (): void {
    enableBoardEvents();
    Event::fake( [ KanbanBoardBroadcast::class ] );

    $board  = kanbanBoard( [], [ [ 'processing', 'A' ] ] );
    $column = kanbanColumn( $board, 'A' );
    $first  = kanbanOrder( [ 'system_status' => 'processing' ] );
    $second = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $first, $column );
    putOnBoard( $second, $column );

    app( KanbanBoardService::class )->move( $second, $column, 5, null, KanbanCardPlacement::top() );

    expect( boardEvents( 'card-reordered' )[0] )->toMatchArray( [ 'order_id' => $second->id, 'column_id' => $column->id, 'actor_user_id' => 5 ] );
} );

it( 'broadcasts cards added and removed by hand or by routing', function (): void {
    enableBoardEvents();
    Event::fake( [ KanbanBoardBroadcast::class ] );

    $board = kanbanBoard( [], [ [ 'processing', 'A' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );

    app( KanbanRoutingService::class )->assign( $order, $board, null, 9 );
    app( KanbanRoutingService::class )->remove( $order, $board, 9 );

    expect( boardEvents( 'card-added' )[0] )->toMatchArray( [ 'order_id' => $order->id, 'column_id' => kanbanColumn( $board, 'A' )->id, 'actor_user_id' => 9 ] )
        ->and( boardEvents( 'card-removed' )[0] )->toMatchArray( [ 'order_id' => $order->id, 'from_column_id' => kanbanColumn( $board, 'A' )->id, 'actor_user_id' => 9 ] );

    app( KanbanRoutingService::class )->route( kanbanOrder( [ 'system_status' => 'processing' ] ) );

    expect( boardEvents( 'card-added' )[1]['actor_user_id'] )->toBeNull();
} );

it( 'broadcasts column configuration changes', function (): void {
    enableBoardEvents();
    Event::fake( [ KanbanBoardBroadcast::class ] );

    $board = kanbanBoard( [], [ [ 'processing', 'A' ] ] );
    app( KanbanColumnService::class )->create( $board, [ 'substatus_id' => OrderSubstatus::factory()->forSystemStatus( 'processing' )->create()->id ], 3 );

    expect( boardEvents( 'config-changed' )[0] )->toMatchArray( [ 'board_id' => $board->id, 'change' => 'column-created', 'actor_user_id' => 3 ] );
} );

it( 'broadcasts automations that fired and failed', function (): void {
    enableBoardEvents();
    Event::fake( [ KanbanBoardBroadcast::class ] );

    $board = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ] ] );
    $to    = kanbanColumn( $board, 'B' );
    KanbanAutomation::factory()->create( [ 'board_id' => $board->id, 'to_column_id' => $to->id, 'trigger_key' => 'not-registered' ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, kanbanColumn( $board, 'A' ) );

    app( KanbanBoardService::class )->move( $order, $to );

    expect( boardEvents( 'automation-failed' )[0] )->toMatchArray( [ 'order_id' => $order->id, 'column_id' => $to->id ] );
} );

it( 'logs a broadcaster failure without failing the write', function (): void {
    enableBoardEvents();
    Broadcast::extend( 'failing', fn () => new class () extends Broadcaster {
        public function auth( $request ): mixed
        {
            return null;
        }

        public function validAuthenticationResponse( $request, $result ): mixed
        {
            return null;
        }

        public function broadcast( array $channels, $event, array $payload = [] ): void
        {
            throw new RuntimeException( 'Broadcaster is down.' );
        }
    } );
    config()->set( 'broadcasting.default', 'failing' );
    config()->set( 'broadcasting.connections.failing', [ 'driver' => 'failing' ] );
    config()->set( 'queue.default', 'sync' );
    Log::shouldReceive( 'channel' )->andReturnSelf();
    Log::shouldReceive( 'error' )->atLeast()->once();

    $board = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, kanbanColumn( $board, 'A' ) );

    $assignment = app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'B' ) );

    expect( $assignment->substatus_id )->toBe( kanbanColumn( $board, 'B' )->substatus_id );
} );

it( 'queues on the configured connection and queue', function (): void {
    config()->set( 'artisanpack.ecommerce.kanban.broadcast_connection', 'redis' );
    config()->set( 'artisanpack.ecommerce.kanban.broadcast_queue', 'kanban' );

    $broadcast = KanbanBoardBroadcast::make( 'card-moved', 4 );

    expect( $broadcast->connection )->toBe( 'redis' )
        ->and( $broadcast->queue )->toBe( 'kanban' )
        ->and( array_keys( $broadcast->broadcastWith() ) )->toBe( [ 'type', 'board_id', 'order_id', 'column_id', 'from_column_id', 'to_column_id', 'position', 'actor_user_id', 'occurred_at' ] );
} );
