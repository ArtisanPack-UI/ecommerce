<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\CustomerRegistered;
use ArtisanPackUI\Ecommerce\Events\CustomerUpdated;
use ArtisanPackUI\Ecommerce\Events\OrderFulfilled;
use ArtisanPackUI\Ecommerce\Events\ProductOutOfStock;
use ArtisanPackUI\Ecommerce\Events\ProductStockLow;
use ArtisanPackUI\Ecommerce\Events\ShipmentCreated;
use ArtisanPackUI\Ecommerce\Events\ShipmentDelivered;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use ArtisanPackUI\Ecommerce\Services\ShipmentService;
use ArtisanPackUI\Ecommerce\ValueObjects\TrackingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses( RefreshDatabase::class );

/**
 * A paid order with one shippable line of `$quantity`.
 */
function eventsOrder( int $quantity = 2 ): Order
{
    $order = Order::factory()->withSystemStatus( 'processing' )->create();
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'quantity' => $quantity ] );

    return $order->refresh();
}

it( 'fires the shipment events and order.fulfilled once everything ships', function (): void {
    Event::fake( [ ShipmentCreated::class, ShipmentDelivered::class, OrderFulfilled::class ] );
    $order    = eventsOrder( 2 );
    $item     = $order->items->first();
    $shipping = app( ShipmentService::class );

    $first = $shipping->create( $order, 'flat-rate', [ $item->id => 1 ] );

    Event::assertDispatched( ShipmentCreated::class, fn ( ShipmentCreated $event ): bool => $event->shipment->is( $first ) && $event->order->is( $order ) );
    Event::assertNotDispatched( OrderFulfilled::class );

    $shipping->create( $order, 'flat-rate', [ $item->id => 1 ] );

    Event::assertDispatchedTimes( ShipmentCreated::class, 2 );
    Event::assertDispatched( OrderFulfilled::class, fn ( OrderFulfilled $event ): bool => $event->order->is( $order ) );

    $shipping->updateTracking( $first, new TrackingStatus( Shipment::STATUS_DELIVERED ) );
    $shipping->updateTracking( $first, new TrackingStatus( Shipment::STATUS_DELIVERED ) );

    Event::assertDispatchedTimes( ShipmentDelivered::class, 1 );
} );

it( 'fires the stock events when on-hand stock crosses the thresholds', function (): void {
    Event::fake( [ ProductStockLow::class, ProductOutOfStock::class ] );
    $item      = InventoryItem::factory()->create( [ 'quantity_on_hand' => 10, 'low_stock_threshold' => 3 ] );
    $inventory = app( InventoryService::class );

    $inventory->adjust( $item, -5, 'sold' );
    Event::assertNotDispatched( ProductStockLow::class );

    $inventory->adjust( $item, -3, 'sold' );
    Event::assertDispatched( ProductStockLow::class, fn ( ProductStockLow $event ): bool => $event->item->is( $item ) && 2 === $event->onHand );
    Event::assertNotDispatched( ProductOutOfStock::class );

    $inventory->adjust( $item, -2, 'sold' );
    Event::assertDispatched( ProductOutOfStock::class, fn ( ProductOutOfStock $event ): bool => $event->item->is( $item ) );
} );

it( 'fires customer.registered and customer.updated, but not for maintained stats', function (): void {
    Event::fake( [ CustomerRegistered::class, CustomerUpdated::class ] );

    $customer = Customer::factory()->create();
    Event::assertDispatched( CustomerRegistered::class, fn ( CustomerRegistered $event ): bool => $event->customer->is( $customer ) );

    $customer->forceFill( [ 'orders_count' => 4, 'last_ordered_at' => now() ] )->save();
    Event::assertNotDispatched( CustomerUpdated::class );

    $customer->update( [ 'first_name' => 'Grace', 'orders_count' => 5 ] );
    Event::assertDispatched( CustomerUpdated::class, fn ( CustomerUpdated $event ): bool => [ 'first_name' ] === $event->changes );
} );

it( 'delivers a webhook for each new event', function (): void {
    Queue::fake();
    WebhookSubscription::factory()->events( [ '*' ] )->create();

    $order = eventsOrder( 1 );
    $item  = InventoryItem::factory()->create( [ 'quantity_on_hand' => 2, 'low_stock_threshold' => 1 ] );

    app( ShipmentService::class )->create( $order, 'flat-rate', [], [ 'status' => Shipment::STATUS_DELIVERED ] );
    app( InventoryService::class )->adjust( $item, -2, 'sold' );
    Customer::factory()->create()->update( [ 'first_name' => 'Ada' ] );

    expect( WebhookDelivery::query()->pluck( 'event' )->unique()->values()->all() )->toContain(
        'shipment.created',
        'shipment.delivered',
        'order.fulfilled',
        'product.stock.low',
        'product.out.of.stock',
        'customer.registered',
        'customer.updated',
    );

    $updated = WebhookDelivery::query()->where( 'event', 'customer.updated' )->first();

    expect( $updated->payload['data']['changes'] )->toBe( [ 'first_name' ] )
        ->and( $updated->payload['data']['customer']['first_name'] )->toBe( 'Ada' );
} );
