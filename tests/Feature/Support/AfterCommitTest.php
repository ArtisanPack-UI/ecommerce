<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Broadcasting\GraphQLSubscriptionBroadcast;
use ArtisanPackUI\Ecommerce\Events\OrderStatusChanged;
use ArtisanPackUI\Ecommerce\Jobs\SendKanbanAutomationWebhookJob;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Notifications\NotificationDispatcher;
use ArtisanPackUI\Ecommerce\Services\OrderStatusMachine;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses( RefreshDatabase::class );

/*
 * Side effects of a change that rolls back must never go out (audit C6).
 */

function rollBackAfter( Closure $change ): void
{
    try {
        DB::transaction( function () use ( $change ): void {
            $change();

            throw new RuntimeException( 'roll back' );
        } );
    } catch ( RuntimeException ) {
        // Expected.
    }
}

it( 'announces a status change only once it commits', function (): void {
    Event::fake( [ OrderStatusChanged::class ] );
    $order = Order::factory()->create( [ 'system_status' => 'pending' ] );
    $fired = 0;
    addAction( 'ap.ecommerce.order.statusChanged', function () use ( &$fired ): void {
        ++$fired;
    } );

    rollBackAfter( fn () => app( OrderStatusMachine::class )->transition( $order, 'processing' ) );

    Event::assertNotDispatched( OrderStatusChanged::class );
    expect( $fired )->toBe( 0 )
        ->and( $order->fresh()->system_status )->toBe( 'pending' );

    app( OrderStatusMachine::class )->transition( $order, 'processing' );

    Event::assertDispatched( OrderStatusChanged::class );
    expect( $fired )->toBe( 1 );
} );

it( 'queues notifications only after the change commits', function (): void {
    // The notification fake records immediately; the real queue honours afterCommit.
    Notification::fake();
    $customer = Customer::factory()->create();

    app( NotificationDispatcher::class )->send( NotificationCatalog::ORDER_CONFIRMATION, $customer, [ 'Order' => [ 'number' => 'A1' ] ] );

    Notification::assertSentTo( $customer, ArtisanPackUI\Ecommerce\Notifications\EcommerceNotification::class, fn ( $notification ): bool => true === $notification->afterCommit );
} );

it( 'queues the kanban webhook only after the change commits', function (): void {
    Queue::fake();

    dispatch( new SendKanbanAutomationWebhookJob( 'https://hooks.example.test/kanban', [] ) );

    Queue::assertPushed( SendKanbanAutomationWebhookJob::class, fn ( SendKanbanAutomationWebhookJob $job ): bool => true === $job->afterCommit );
} );

it( 'broadcasts nothing for a change that rolls back', function (): void {
    Event::fake( [ GraphQLSubscriptionBroadcast::class ] );

    rollBackAfter( fn () => event( new GraphQLSubscriptionBroadcast( 'orderStatusChanged', 'ecommerce.admin', [] ) ) );

    Event::assertNotDispatched( GraphQLSubscriptionBroadcast::class );
} );

it( 'logs a listener that throws after commit instead of failing the change', function (): void {
    $logger = Mockery::spy();
    Illuminate\Support\Facades\Log::shouldReceive( 'channel' )->with( 'ecommerce' )->andReturn( $logger );
    // report() logs through the default channel too.
    Illuminate\Support\Facades\Log::shouldReceive( 'error' )->zeroOrMoreTimes();

    DB::transaction( fn () => AfterCommit::run( 'test.hook', static fn () => throw new RuntimeException( 'boom' ) ) );

    $logger->shouldHaveReceived( 'error' )->once()->withArgs( fn ( string $message, array $context ): bool => 'test.hook' === $context['hook'] && 'boom' === $context['message'] );
} );
