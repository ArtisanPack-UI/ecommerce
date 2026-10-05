<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\OrderFulfilled;
use ArtisanPackUI\Ecommerce\Listeners\DispatchWebhooksForEvent;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Services\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Queue::fake();
} );

it( 'queues a delivery named after the event with its serialized payload', function (): void {
    $subscription = WebhookSubscription::factory()->events( [ 'order.fulfilled' ] )->create();
    $order        = Order::factory()->create( [ 'order_number' => 'K7QM2XW9' ] );

    app( DispatchWebhooksForEvent::class )->handle( new OrderFulfilled( $order ) );

    $delivery = WebhookDelivery::query()->sole();

    expect( $delivery->subscription_id )->toBe( $subscription->id )
        ->and( $delivery->event )->toBe( 'order.fulfilled' )
        ->and( $delivery->payload['data']['order']['order_number'] )->toBe( 'K7QM2XW9' )
        ->and( $delivery->order_id )->toBe( $order->id );
} );

it( 'skips subscriptions that don\'t listen for the event', function (): void {
    WebhookSubscription::factory()->events( [ 'order.refunded' ] )->create();

    app( DispatchWebhooksForEvent::class )->handle( new OrderFulfilled( Order::factory()->create() ) );

    expect( WebhookDelivery::query()->count() )->toBe( 0 );
} );

it( 'waits for the surrounding transaction to commit, and sends nothing if it rolls back', function (): void {
    WebhookSubscription::factory()->events( [ '*' ] )->create();
    $order = Order::factory()->create();

    try {
        DB::transaction( function () use ( $order ): void {
            app( DispatchWebhooksForEvent::class )->handle( new OrderFulfilled( $order ) );

            throw new RuntimeException( 'roll back' );
        } );
    } catch ( RuntimeException ) {
    }

    expect( WebhookDelivery::query()->count() )->toBe( 0 );
} );

it( 'reports a dispatcher failure instead of breaking the caller', function (): void {
    Exceptions::fake();

    $this->mock( WebhookDispatcher::class, function ( $mock ): void {
        $mock->shouldReceive( 'dispatch' )->once()->andThrow( new RuntimeException( 'queue down' ) );
    } );

    app( DispatchWebhooksForEvent::class )->handle( new OrderFulfilled( Order::factory()->create() ) );

    Exceptions::assertReported( fn ( RuntimeException $e ): bool => 'queue down' === $e->getMessage() );
} );
