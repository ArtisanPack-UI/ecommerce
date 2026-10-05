<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Broadcasting\GraphQLSubscriptionBroadcast;
use ArtisanPackUI\Ecommerce\Events\OrderStatusChanged;
use ArtisanPackUI\Ecommerce\Listeners\BroadcastGraphQLSubscriptions;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use ArtisanPackUI\Ecommerce\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Event::fake( [ GraphQLSubscriptionBroadcast::class ] );
    app( BroadcastGraphQLSubscriptions::class )->subscribe( app( 'events' ) );
} );

it( 'broadcasts order status changes on the admin channel in GraphQL response shape', function (): void {
    $order = Order::factory()->create();

    event( new OrderStatusChanged( $order, 'pending', 'processing' ) );

    Event::assertDispatched( GraphQLSubscriptionBroadcast::class, function ( GraphQLSubscriptionBroadcast $broadcast ) use ( $order ): bool {
        $body = $broadcast->broadcastWith();

        return 'orderStatusChanged' === $broadcast->broadcastAs()
            && 'private-ecommerce.admin' === $broadcast->broadcastOn()[0]->name
            && $order->id === $body['data']['orderStatusChanged']['order']['id']
            && 'processing' === $body['data']['orderStatusChanged']['to'];
    } );
} );

it( 'broadcasts stock changes from the inventory hook', function (): void {
    $item = InventoryItem::factory()->create( [ 'quantity_on_hand' => 5 ] );

    app( InventoryService::class )->adjust( $item, -2, 'sale' );

    Event::assertDispatched( GraphQLSubscriptionBroadcast::class, fn ( GraphQLSubscriptionBroadcast $broadcast ): bool => 'stockChanged' === $broadcast->field
        && -2 === $broadcast->payload['delta']
        && 3 === $broadcast->payload['new_level']
        && $item->id === $broadcast->payload['inventory_item']['id'] );
} );

it( 'broadcasts submitted reviews', function (): void {
    $review = app( ReviewService::class )->submit( Product::factory()->create(), [ 'rating' => 4, 'author_name' => 'Ada' ] );

    Event::assertDispatched( GraphQLSubscriptionBroadcast::class, fn ( GraphQLSubscriptionBroadcast $broadcast ): bool => 'reviewSubmitted' === $broadcast->field
        && $review->id === $broadcast->payload['review']['id']
        && 'pending' === $broadcast->payload['review']['status'] );
} );

it( 'is off unless enabled', function (): void {
    expect( config( 'artisanpack.ecommerce.graphql.subscriptions' ) )->toBeFalse();
} );
