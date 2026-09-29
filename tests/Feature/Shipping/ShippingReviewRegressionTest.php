<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Services\CurrencyConverter;
use ArtisanPackUI\Ecommerce\Services\ShipmentService;
use ArtisanPackUI\Ecommerce\Shipping\LocalPickupHandoff;
use ArtisanPackUI\Ecommerce\Shipping\ZoneShippingRateProvider;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Money\Currency;
use Money\Money;

uses( RefreshDatabase::class );

it( 'scales conversions by each currency\'s minor units', function (): void {
    config()->set( 'artisanpack.ecommerce.currency.rates', [
        'USD' => [ 'JPY' => 15_000_000_000, 'KWD' => 30_700_000 ],
        'JPY' => [ 'USD' => 666_667 ],
    ] );

    $converter = app( CurrencyConverter::class );

    // $5.00 → ¥750; $5.00 → KWD 1.535 (three decimals); ¥750 → $5.00.
    expect( $converter->convert( Money::USD( 500 ), 'JPY' ) )->toEqual( new Money( 750, new Currency( 'JPY' ) ) );
    expect( $converter->convert( Money::USD( 500 ), 'KWD' ) )->toEqual( new Money( 1_535, new Currency( 'KWD' ) ) );
    expect( $converter->convert( new Money( 750, new Currency( 'JPY' ) ), 'USD' ) )->toEqual( Money::USD( 500 ) );
} );

it( 'inherits the product weight unit when a variant overrides only the weight', function (): void {
    $zone = ShippingZone::factory()->create( [ 'country_codes' => [ 'US' ] ] );
    ShippingMethod::factory()->create( [ 'zone_id' => $zone->id, 'key' => 'weight-based', 'config' => [ 'unit' => 'LBS', 'tiers' => [
        [ 'max_weight' => 1, 'amount' => 400 ],
        [ 'max_weight' => null, 'amount' => 1_200 ],
    ] ] ] );

    $product = Product::factory()->create( [ 'weight' => '5', 'weight_unit' => 'lb' ] );
    $variant = ProductVariant::factory()->create( [ 'product_id' => $product->id, 'weight' => '5', 'weight_unit' => null ] );

    $rates = app( ZoneShippingRateProvider::class )->getRatesForCart(
        cartWithLines( [ [ 'product' => $product, 'variant' => $variant ] ] ),
        new Address( '1 Main', 'Chicago', 'US' ),
    );

    expect( (int) $rates->first()->amount->getAmount() )->toBe( 1_200 );
} );

it( 'rolls fulfillment status up to lines and the order and fires the fulfilled hooks', function (): void {
    $order = Order::factory()->create();
    $a     = OrderItem::factory()->create( [ 'order_id' => $order->id, 'quantity' => 2 ] );
    $b     = OrderItem::factory()->create( [ 'order_id' => $order->id, 'quantity' => 1 ] );

    $fired = [];
    addAction( 'ap.ecommerce.order.itemFulfilled', function ( Order $o, OrderItem $item ) use ( &$fired ): void {
        $fired[] = 'item:' . $item->id;
    } );
    addAction( 'ap.ecommerce.order.fulfilled', function () use ( &$fired ): void {
        $fired[] = 'order';
    } );

    $service = app( ShipmentService::class );
    $service->create( $order, 'flat-rate', [ $a->id => 1 ] );

    expect( $a->fresh()->fulfillment_status )->toBe( 'partial' );
    expect( $order->fresh()->fulfillment_status )->toBe( 'partial' );
    expect( $fired )->toBe( [] );

    $service->create( $order, 'flat-rate' );

    expect( $a->fresh()->fulfillment_status )->toBe( 'fulfilled' );
    expect( $b->fresh()->fulfillment_status )->toBe( 'fulfilled' );
    expect( $order->fresh()->fulfillment_status )->toBe( 'fulfilled' );
    expect( $fired )->toBe( [ 'item:' . $a->id, 'item:' . $b->id, 'order' ] );
} );

it( 'skips lines that do not need shipping and refuses terminal orders', function (): void {
    $order    = Order::factory()->create();
    $physical = OrderItem::factory()->create( [ 'order_id' => $order->id ] );
    $digital  = OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => Product::factory()->digital()->create()->id ] );

    $service = app( ShipmentService::class );

    expect( $service->remainingQuantities( $order ) )->toBe( [ $physical->id => 1 ] );
    expect( fn () => $service->create( $order, 'flat-rate', [ $digital->id => 1 ] ) )->toThrow( InvalidArgumentException::class );

    $service->create( $order, 'flat-rate' );
    expect( $order->fresh()->fulfillment_status )->toBe( 'fulfilled' );

    $cancelled = Order::factory()->withSystemStatus( 'cancelled' )->create();
    OrderItem::factory()->create( [ 'order_id' => $cancelled->id ] );

    expect( fn () => $service->create( $cancelled, 'flat-rate' ) )->toThrow( InvalidArgumentException::class );
} );

it( 'fires order.delivered when a shipment is created already delivered', function (): void {
    $order = Order::factory()->create();
    OrderItem::factory()->create( [ 'order_id' => $order->id ] );

    $delivered = 0;
    addAction( 'ap.ecommerce.order.delivered', function () use ( &$delivered ): void {
        $delivered++;
    } );

    $shipment = app( ShipmentService::class )->create( $order, 'flat-rate', [], [ 'status' => 'delivered' ] );

    expect( $delivered )->toBe( 1 );
    expect( $shipment->delivered_at )->not->toBeNull();
} );

it( 'fires trackingUpdated on pickup redemption and refuses to re-issue a collected code', function (): void {
    $order = Order::factory()->create();
    OrderItem::factory()->create( [ 'order_id' => $order->id ] );

    $payload = null;
    addAction( 'ap.ecommerce.shipping.pickupReady', function ( $s, $o, string $qr ) use ( &$payload ): void {
        $payload = $qr;
    } );

    $tracking = [];
    addAction( 'ap.ecommerce.shipping.trackingUpdated', function ( $s, $status ) use ( &$tracking ): void {
        $tracking[] = $status->status;
    } );

    $shipment = app( ShipmentService::class )->create( $order, 'local-pickup' );
    $handoff  = app( LocalPickupHandoff::class );

    expect( $handoff->redeem( $shipment, $payload ) )->toBeTrue();
    expect( $tracking )->toBe( [ 'delivered' ] );
    expect( fn () => $handoff->issue( $shipment->fresh() ) )->toThrow( LogicException::class );
} );
