<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\ShippingLabelProvider;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Registries\ShippingLabelProviderRegistry;
use ArtisanPackUI\Ecommerce\Services\ShipmentService;
use ArtisanPackUI\Ecommerce\Shipping\LocalPickupHandoff;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingLabel;
use ArtisanPackUI\Ecommerce\ValueObjects\TrackingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->service = app( ShipmentService::class );
    $this->order   = Order::factory()->create();
    $this->lineA   = OrderItem::factory()->create( [ 'order_id' => $this->order->id, 'quantity' => 3 ] );
    $this->lineB   = OrderItem::factory()->create( [ 'order_id' => $this->order->id, 'quantity' => 1 ] );
} );

it( 'creates a partial shipment and fires shipmentCreated + order.shipped', function (): void {
    $fired = [];
    addAction( 'ap.ecommerce.shipping.shipmentCreated', function ( Shipment $s ) use ( &$fired ): void {
        $fired[] = 'shipmentCreated';
    } );
    addAction( 'ap.ecommerce.order.shipped', function ( Order $o, Shipment $s ) use ( &$fired ): void {
        $fired[] = 'shipped';
    } );

    $shipment = $this->service->create( $this->order, 'flat-rate', [ $this->lineA->id => 2 ], [ 'carrier' => 'usps' ] );

    expect( $shipment->status )->toBe( Shipment::STATUS_PENDING );
    expect( $shipment->carrier )->toBe( 'usps' );
    expect( $shipment->items )->toHaveCount( 1 );
    expect( $fired )->toBe( [ 'shipmentCreated', 'shipped' ] );
    expect( $this->service->remainingQuantities( $this->order ) )->toBe( [ $this->lineA->id => 1, $this->lineB->id => 1 ] );
} );

it( 'ships every unshipped unit when no quantities are given', function (): void {
    $this->service->create( $this->order, 'flat-rate', [ $this->lineA->id => 1 ] );

    $rest = $this->service->create( $this->order, 'flat-rate' );

    expect( $rest->items->pluck( 'quantity', 'order_item_id' )->all() )->toBe( [ $this->lineA->id => 2, $this->lineB->id => 1 ] );
    expect( fn () => $this->service->create( $this->order, 'flat-rate' ) )->toThrow( InvalidArgumentException::class );
} );

it( 'refuses to over-ship or ship foreign items', function ( string $case ): void {
    $quantities = match ( $case ) {
        'over-ship'    => [ $this->lineA->id => 4 ],
        'zero'         => [ $this->lineA->id => 0 ],
        'foreign item' => [ OrderItem::factory()->create()->id => 1 ],
    };

    $this->service->create( $this->order, 'flat-rate', $quantities );
} )->with( [ 'over-ship', 'zero', 'foreign item' ] )->throws( InvalidArgumentException::class );

it( 'issues a QR handoff code for local pickup and redeems it exactly once', function (): void {
    $payload = null;
    addAction( 'ap.ecommerce.shipping.pickupReady', function ( Shipment $s, Order $o, string $qr ) use ( &$payload ): void {
        $payload = $qr;
    } );

    $delivered = 0;
    addAction( 'ap.ecommerce.order.delivered', function () use ( &$delivered ): void {
        $delivered++;
    } );

    $shipment = $this->service->create( $this->order, 'local-pickup' );
    $handoff  = app( LocalPickupHandoff::class );

    expect( $payload )->toStartWith( 'ap-ecommerce-pickup:' . $shipment->id . ':' );
    expect( $shipment->fresh()->meta['pickup']['code_hash'] )->toBe( hash( 'sha256', explode( ':', $payload )[2] ) );

    expect( $handoff->redeem( $shipment->fresh(), $payload . 'x' ) )->toBeFalse();
    expect( $handoff->redeem( $shipment->fresh(), $payload ) )->toBeTrue();
    expect( $handoff->redeem( $shipment->fresh(), $payload ) )->toBeFalse();

    expect( $shipment->fresh()->status )->toBe( Shipment::STATUS_DELIVERED );
    expect( $delivered )->toBe( 1 );
} );

it( 'rejects a payload issued for a different shipment', function (): void {
    $first  = $this->service->create( $this->order, 'local-pickup', [ $this->lineA->id => 1 ] );
    $second = $this->service->create( $this->order, 'local-pickup', [ $this->lineB->id => 1 ] );

    $payload = app( LocalPickupHandoff::class )->issue( $first->fresh() );

    expect( app( LocalPickupHandoff::class )->verify( $second->fresh(), $payload ) )->toBeFalse();
} );

it( 'applies tracking updates, stamping shipped / delivered times once', function (): void {
    $shipment = $this->service->create( $this->order, 'flat-rate' );

    $events = [];
    addAction( 'ap.ecommerce.shipping.trackingUpdated', function ( Shipment $s, TrackingStatus $t ) use ( &$events ): void {
        $events[] = $t->status;
    } );

    $this->service->updateTracking( $shipment, new TrackingStatus( Shipment::STATUS_IN_TRANSIT, trackingNumber: '1Z999' ) );
    $this->service->updateTracking( $shipment, new TrackingStatus( Shipment::STATUS_DELIVERED ) );

    expect( $shipment->fresh()->tracking_number )->toBe( '1Z999' );
    expect( $shipment->fresh()->shipped_at )->not->toBeNull();
    expect( $shipment->fresh()->delivered_at )->not->toBeNull();
    expect( $events )->toBe( [ 'in_transit', 'delivered' ] );

    expect( fn () => $this->service->updateTracking( $shipment, new TrackingStatus( 'lost-in-space' ) ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'buys a label through a registered label provider', function (): void {
    app( ShippingLabelProviderRegistry::class )->register( 'fake-labels', new class implements ShippingLabelProvider {
        public function key(): string
        {
            return 'fake-labels';
        }

        public function buyLabel( Shipment $shipment ): ShippingLabel
        {
            return new ShippingLabel( 77, 'fake-labels', '9400TEST', 'https://track.test/9400TEST', 'usps', 'priority' );
        }

        public function voidLabel( ShippingLabel $label ): void
        {
        }

        public function trackLabel( ShippingLabel $label ): TrackingStatus
        {
            return new TrackingStatus( Shipment::STATUS_IN_TRANSIT );
        }
    } );

    $shipment = $this->service->create( $this->order, 'flat-rate' );
    $label    = $this->service->buyLabel( $shipment, 'fake-labels' );

    expect( $label->id )->toBe( 77 );
    expect( $shipment->fresh()->only( [ 'label_id', 'tracking_number', 'carrier', 'service' ] ) )
        ->toBe( [ 'label_id' => 77, 'tracking_number' => '9400TEST', 'carrier' => 'usps', 'service' => 'priority' ] );
} );

it( 'refuses a second label for the same shipment', function (): void {
    $provider = Mockery::mock( ShippingLabelProvider::class );
    $provider->shouldReceive( 'key' )->andReturn( 'once-labels' );
    $provider->shouldReceive( 'buyLabel' )->once()->andReturn( new ShippingLabel( 88, 'once-labels', 'T1' ) );

    app( ShippingLabelProviderRegistry::class )->register( 'once-labels', $provider );

    $shipment = $this->service->create( $this->order, 'flat-rate' );
    $stale    = $shipment->replicate()->setRawAttributes( $shipment->getAttributes() );

    $this->service->buyLabel( $shipment, 'once-labels' );

    expect( $shipment->label_id )->toBe( 88 )
        ->and( fn () => $this->service->buyLabel( $stale, 'once-labels' ) )->toThrow( InvalidArgumentException::class, 'already has a label' );
} );

it( 'clears a tracking field on an empty string and keeps it on null', function (): void {
    $shipment = $this->service->create( $this->order, 'flat-rate', [], [ 'tracking_number' => '1Z1', 'tracking_url' => 'https://track.test/1Z1' ] );

    $this->service->updateTracking( $shipment, new TrackingStatus( Shipment::STATUS_IN_TRANSIT, trackingNumber: null, trackingUrl: '' ) );

    expect( $shipment->fresh()->tracking_number )->toBe( '1Z1' )
        ->and( $shipment->fresh()->tracking_url )->toBeNull();
} );

it( 'fires order.fulfilling with the order before any shipment row is written', function (): void {
    $fired = null;

    addAction( 'ap.ecommerce.order.fulfilling', function ( Order $order ) use ( &$fired ): void {
        $fired = [ $order->id, Shipment::query()->where( 'order_id', $order->id )->count() ];
    } );

    $this->service->create( $this->order, 'flat-rate', [ $this->lineA->id => 1 ] );

    expect( $fired )->toBe( [ $this->order->id, 0 ] );
} );

it( 'aborts the shipment when an order.fulfilling listener throws', function (): void {
    addAction( 'ap.ecommerce.order.fulfilling', function (): void {
        throw new RuntimeException( 'warehouse closed' );
    } );

    expect( fn () => $this->service->create( $this->order, 'flat-rate' ) )->toThrow( RuntimeException::class, 'warehouse closed' );
    expect( Shipment::query()->where( 'order_id', $this->order->id )->count() )->toBe( 0 );
} );

it( 'does not fire order.fulfilling for an order that can no longer be shipped', function (): void {
    $this->order->update( [ 'system_status' => 'cancelled' ] );
    $fired = false;

    addAction( 'ap.ecommerce.order.fulfilling', function () use ( &$fired ): void {
        $fired = true;
    } );

    expect( fn () => $this->service->create( $this->order, 'flat-rate' ) )->toThrow( InvalidArgumentException::class );
    expect( $fired )->toBeFalse();
} );

it( 'does not fire order.fulfilling when the requested quantities are rejected', function (): void {
    $fired = false;

    addAction( 'ap.ecommerce.order.fulfilling', function () use ( &$fired ): void {
        $fired = true;
    } );

    expect( fn () => $this->service->create( $this->order, 'flat-rate', [ $this->lineA->id => 99 ] ) )->toThrow( InvalidArgumentException::class );
    expect( fn () => $this->service->create( $this->order, 'flat-rate', [], [ 'status' => 'teleported' ] ) )->toThrow( InvalidArgumentException::class );
    expect( $fired )->toBeFalse();
} );
