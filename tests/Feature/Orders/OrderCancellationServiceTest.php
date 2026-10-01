<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Events\OrderCancelled;
use ArtisanPackUI\Ecommerce\Exceptions\OrderNotCancellableException;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use ArtisanPackUI\Ecommerce\Services\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->gateway = Mockery::mock( PaymentGateway::class );
    $this->gateway->shouldReceive( 'key' )->andReturn( 'fake' );

    app( PaymentGatewayRegistry::class )->register( 'fake', $this->gateway );

    $this->service = app( OrderCancellationService::class );
} );

/**
 * An order holding a reservation of `$quantity` units.
 *
 * @return array{0: Order, 1: InventoryItem}
 */
function cancellableOrderWithReservation( int $quantity = 2, array $attributes = [] ): array
{
    $order = Order::factory()->create( $attributes + [ 'payment_gateway_key' => 'fake', 'total_amount' => 5_000 ] );
    $item  = InventoryItem::factory()->create( [ 'quantity_on_hand' => 10 ] );

    app( InventoryService::class )->reserve( $item, $order, $quantity );

    return [ $order, $item->fresh() ];
}

it( 'assesses what cancelling an unpaid order will do', function (): void {
    [ $order, $item ] = cancellableOrderWithReservation( 3 );

    $summary = $this->service->assess( $order );

    expect( $summary->cancellable )->toBeTrue()
        ->and( $summary->blockedReason )->toBeNull()
        ->and( $summary->reservations )->toBe( [ [ 'inventory_item_id' => $item->id, 'quantity' => 3 ] ] )
        ->and( $summary->reservedUnits() )->toBe( 3 )
        ->and( $summary->voidsPayment )->toBeTrue()
        ->and( $summary->refundOwed() )->toBeFalse();

    expect( $order->fresh()->system_status )->toBe( 'pending' );
} );

it( 'cancels an unpaid order, releases its stock, voids the payment, and records it', function (): void {
    Event::fake( [ OrderCancelled::class ] );

    [ $order, $item ] = cancellableOrderWithReservation( 2 );

    $this->gateway->shouldReceive( 'voidPendingPayment' )->once();

    $hooks = [];
    addAction( 'ap.ecommerce.order.cancelling', function ( Order $o, string $reason ) use ( &$hooks ): void {
        $hooks[] = 'cancelling:' . $reason;
    } );
    addAction( 'ap.ecommerce.order.cancelled', function ( Order $o ) use ( &$hooks ): void {
        $hooks[] = 'cancelled:' . $o->system_status;
    } );

    $summary = $this->service->cancel( $order, 'Customer asked', 7 );

    expect( $summary->order->system_status )->toBe( 'cancelled' )
        ->and( $summary->order->payment_status )->toBe( OrderCancellationService::PAYMENT_STATUS_VOIDED )
        ->and( $summary->reservations )->toBe( [ [ 'inventory_item_id' => $item->id, 'quantity' => 2 ] ] )
        ->and( $summary->voidsPayment )->toBeTrue()
        ->and( $summary->refundOwedAmount )->toBe( 0 );

    expect( InventoryReservation::query()->count() )->toBe( 0 )
        ->and( $item->fresh()->quantity_reserved )->toBe( 0 )
        ->and( $hooks )->toBe( [ 'cancelling:Customer asked', 'cancelled:cancelled' ] );

    $entry = OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'order.cancelled' )->sole();

    expect( $entry->actor_user_id )->toBe( 7 )
        ->and( $entry->payload['reason'] )->toBe( 'Customer asked' )
        ->and( $entry->payload['from'] )->toBe( 'pending' )
        ->and( $entry->payload['payment_voided'] )->toBeTrue();

    expect( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'order.status_changed' )->exists() )->toBeTrue();

    Event::assertDispatched( OrderCancelled::class, fn ( OrderCancelled $event ): bool => $event->order->is( $order ) && 'Customer asked' === $event->reason );
} );

it( 'cancels a paid order without refunding and reports the refund still owed', function (): void {
    $this->gateway->shouldNotReceive( 'voidPendingPayment' );
    $this->gateway->shouldNotReceive( 'refund' );

    $order = Order::factory()->withSystemStatus( 'processing' )->create( [
        'payment_status'        => 'partially_refunded',
        'payment_gateway_key'   => 'fake',
        'total_amount'          => 5_000,
        'total_refunded_amount' => 1_000,
    ] );

    $summary = $this->service->cancel( $order, 'Out of stock' );

    expect( $summary->order->system_status )->toBe( 'cancelled' )
        ->and( $summary->order->payment_status )->toBe( 'partially_refunded' )
        ->and( $summary->voidsPayment )->toBeFalse()
        ->and( $summary->refundOwed() )->toBeTrue()
        ->and( $summary->refundOwedAmount )->toBe( 4_000 );
} );

it( 'refuses an order that is already cancelled', function (): void {
    $order = Order::factory()->withSystemStatus( 'cancelled' )->create();

    expect( $this->service->assess( $order )->cancellable )->toBeFalse();

    $this->service->cancel( $order, 'Again' );
} )->throws( OrderNotCancellableException::class, 'already cancelled' );

it( 'refuses statuses with no edge to cancelled', function ( string $status ): void {
    $order = Order::factory()->withSystemStatus( $status )->create();

    $summary = $this->service->assess( $order );

    expect( $summary->cancellable )->toBeFalse()
        ->and( $summary->blockedReason )->toContain( 'can no longer be cancelled' );

    expect( fn () => $this->service->cancel( $order, 'No' ) )->toThrow( OrderNotCancellableException::class );
    expect( $order->fresh()->system_status )->toBe( $status );
} )->with( [ 'complete', 'refunded' ] );

it( 'requires a reason', function (): void {
    $order = Order::factory()->create();

    $this->service->cancel( $order, '   ' );
} )->throws( InvalidArgumentException::class );

it( 'aborts the cancel when the void fails', function (): void {
    [ $order ] = cancellableOrderWithReservation( 1 );

    $this->gateway->shouldReceive( 'voidPendingPayment' )->andThrow( new RuntimeException( 'gateway down' ) );

    expect( fn () => $this->service->cancel( $order, 'Fraud' ) )->toThrow( OrderNotCancellableException::class, 'gateway down' );

    expect( $order->fresh()->system_status )->toBe( 'pending' )
        ->and( InventoryReservation::query()->count() )->toBe( 1 );
} );

it( 'does not void when the gateway is not registered', function (): void {
    $order = Order::factory()->create( [ 'payment_gateway_key' => 'gone' ] );

    $summary = $this->service->cancel( $order, 'Duplicate' );

    expect( $summary->voidsPayment )->toBeFalse()
        ->and( $summary->order->payment_status )->toBe( 'pending' );
} );

it( 'does not mark a payment captured during the cancel as voided', function (): void {
    $order = Order::factory()->create( [ 'payment_gateway_key' => 'fake', 'payment_status' => 'pending', 'total_amount' => 5_000 ] );

    // The capture lands after the caller loaded the order but before the cancel locks it.
    Illuminate\Support\Facades\DB::table( 'orders' )->where( 'id', $order->id )->update( [ 'payment_status' => 'paid' ] );

    $this->gateway->shouldNotReceive( 'voidPendingPayment' );

    $summary = $this->service->cancel( $order, 'Changed mind' );

    expect( $summary->order->payment_status )->toBe( 'paid' )
        ->and( $summary->voidsPayment )->toBeFalse()
        ->and( $summary->refundOwedAmount )->toBe( 5_000 );
} );

it( 'releases a reservation once when the expiry sweep already took it', function (): void {
    [ $order, $item ] = cancellableOrderWithReservation( 2 );

    InventoryReservation::query()->update( [ 'expires_at' => now()->subMinute() ] );
    app( InventoryService::class )->releaseExpired();

    $this->gateway->shouldReceive( 'voidPendingPayment' );
    $summary = $this->service->cancel( $order, 'Too late' );

    expect( $summary->reservations )->toBe( [] )
        ->and( $item->fresh()->quantity_reserved )->toBe( 0 );
} );
