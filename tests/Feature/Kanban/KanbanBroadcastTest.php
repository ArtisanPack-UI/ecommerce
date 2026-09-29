<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Broadcasting\GraphQLSubscriptionBroadcast;
use ArtisanPackUI\Ecommerce\Events\KanbanCardMoved;
use ArtisanPackUI\Ecommerce\Listeners\BroadcastKanbanCardMoved;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use ArtisanPackUI\Ecommerce\Services\KanbanBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/KanbanTestHelpers.php';
require_once __DIR__ . '/../Api/ApiTestHelpers.php';

uses( RefreshDatabase::class );

/**
 * Turns broadcasting on the way a host app would (config before boot).
 */
function enableKanbanBroadcasts(): void
{
    config()->set( 'artisanpack.ecommerce.kanban.broadcast', true );
    config()->set( 'artisanpack.ecommerce.kanban.auto_route', false );

    ( fn () => $this->registerKanbanListeners() )->call( new EcommerceServiceProvider( app() ) );
}

it( 'is off unless enabled', function (): void {
    expect( config( 'artisanpack.ecommerce.kanban.broadcast' ) )->toBeFalse();

    Event::fake( [ GraphQLSubscriptionBroadcast::class ] );
    $board = kanbanBoard( [], [ [ 'processing', 'A' ], [ 'processing', 'B' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, kanbanColumn( $board, 'A' ) );

    app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'B' ) );

    Event::assertNotDispatched( GraphQLSubscriptionBroadcast::class );
} );

it( 'broadcasts card moves on the board channel in GraphQL subscription shape', function (): void {
    enableKanbanBroadcasts();
    Event::fake( [ GraphQLSubscriptionBroadcast::class ] );

    $board = kanbanBoard( [], [ [ 'processing', 'Printing' ], [ 'processing', 'Packing' ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );
    putOnBoard( $order, kanbanColumn( $board, 'Printing' ) );

    app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'Packing' ) );

    Event::assertDispatched( GraphQLSubscriptionBroadcast::class, function ( GraphQLSubscriptionBroadcast $broadcast ) use ( $board, $order ): bool {
        $body = $broadcast->broadcastWith()['data']['kanbanCardMoved'];

        return 'kanbanCardMoved' === $broadcast->broadcastAs()
            && 'private-ecommerce.kanban.board.' . $board->id === $broadcast->broadcastOn()[0]->name
            && $order->id === $body['card']['order_id']
            && kanbanColumn( $board, 'Packing' )->id === $body['card']['column_id']
            && kanbanColumn( $board, 'Printing' )->id === $body['from_column_id']
            && $board->id === $body['board_id']
            && [] !== $body['card']['widgets'];
    } );
} );

it( 'authorizes the board channel with the kanbanBoard.view ability', function (): void {
    enableKanbanBroadcasts();

    $board    = kanbanBoard();
    $callback = Broadcast::getChannels()[ BroadcastKanbanCardMoved::CHANNEL ] ?? null;

    expect( $callback )->toBeCallable()
        ->and( $callback( ecommerceShopper(), (string) $board->id ) )->toBeFalse();

    Gate::define( 'ecommerce.kanbanBoard.view', fn (): bool => true );

    expect( $callback( ecommerceShopper(), (string) $board->id ) )->toBeTrue()
        ->and( $callback( ecommerceShopper(), '999999' ) )->toBeFalse();
} );

it( 'names the channel per board', function (): void {
    expect( BroadcastKanbanCardMoved::channel( 12 ) )->toBe( 'ecommerce.kanban.board.12' );

    Event::fake( [ GraphQLSubscriptionBroadcast::class ] );

    // A move event for an order no longer on the board broadcasts nothing.
    $board = kanbanBoard( [], [ [ 'processing', 'A' ] ] );
    ( new BroadcastKanbanCardMoved() )->handle( new KanbanCardMoved( kanbanOrder(), kanbanColumn( $board, 'A' ), kanbanColumn( $board, 'A' ), $board ) );

    Event::assertNotDispatched( GraphQLSubscriptionBroadcast::class );
} );
