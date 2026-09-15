<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderEdit;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\OrderNote;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'persists an order with the full spec §3.15 attribute set', function (): void {
    $order = Order::factory()->create( [
        'email'              => 'buyer@example.com',
        'currency'           => 'USD',
        'base_currency'      => 'USD',
        'fx_rate_to_base_e8' => 100_000_000,
        'subtotal_amount'    => 5_000,
        'total_amount'       => 5_500,
        'shipping_address'   => [
            'address1'     => '1 Main St',
            'city'         => 'Portland',
            'country_code' => 'US',
        ],
        'meta' => [ 'source' => 'web' ],
    ] );

    $fresh = Order::query()->find( $order->id );

    expect( $fresh->email )->toBe( 'buyer@example.com' );
    expect( $fresh->currency )->toBe( 'USD' );
    expect( $fresh->fx_rate_to_base_e8 )->toBe( 100_000_000 );
    expect( $fresh->subtotal_amount )->toBe( 5_000 );
    expect( $fresh->total_amount )->toBe( 5_500 );
    expect( $fresh->shipping_address )->toBe( [
        'address1'     => '1 Main St',
        'city'         => 'Portland',
        'country_code' => 'US',
    ] );
    expect( $fresh->meta )->toBe( [ 'source' => 'web' ] );
    expect( $fresh->is_claimed )->toBeTrue();
} );

it( 'nulls out substatus_id when the sub-status row is deleted', function (): void {
    $substatus = OrderSubstatus::query()->where( 'system_status', 'processing' )->first();
    $order     = Order::factory()->create( [ 'substatus_id' => $substatus->id ] );

    $substatus->delete();

    expect( Order::query()->find( $order->id )->substatus_id )->toBeNull();
} );

it( 'nulls out customer_id when the customer is deleted', function (): void {
    $customer = Customer::factory()->create();
    $order    = Order::factory()->forCustomer( $customer )->create();

    $customer->delete();

    expect( Order::query()->find( $order->id )->customer_id )->toBeNull();
} );

it( 'cascade-deletes items, notes, timeline entries, and edits when the order is deleted', function (): void {
    $order = Order::factory()->create();

    OrderItem::factory()->for( $order )->create();
    OrderNote::factory()->for( $order )->create();
    OrderTimelineEntry::factory()->for( $order )->create();
    OrderEdit::factory()->for( $order )->create();

    expect( OrderItem::query()->where( 'order_id', $order->id )->count() )->toBe( 1 );
    expect( OrderNote::query()->where( 'order_id', $order->id )->count() )->toBe( 1 );
    expect( OrderTimelineEntry::query()->where( 'order_id', $order->id )->count() )->toBe( 1 );
    expect( OrderEdit::query()->where( 'order_id', $order->id )->count() )->toBe( 1 );

    $order->delete();

    expect( OrderItem::query()->where( 'order_id', $order->id )->count() )->toBe( 0 );
    expect( OrderNote::query()->where( 'order_id', $order->id )->count() )->toBe( 0 );
    expect( OrderTimelineEntry::query()->where( 'order_id', $order->id )->count() )->toBe( 0 );
    expect( OrderEdit::query()->where( 'order_id', $order->id )->count() )->toBe( 0 );
} );

it( 'exposes items/notes/timeline/edits relations', function (): void {
    $order = Order::factory()->create();

    OrderItem::factory()->for( $order )->count( 2 )->create();
    OrderNote::factory()->for( $order )->create();
    OrderTimelineEntry::factory()->for( $order )->count( 3 )->create();
    OrderEdit::factory()->for( $order )->create();

    $order->refresh();

    expect( $order->items )->toHaveCount( 2 );
    expect( $order->notes )->toHaveCount( 1 );
    expect( $order->timelineEntries )->toHaveCount( 3 );
    expect( $order->edits )->toHaveCount( 1 );
} );
