<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Listeners\TrackCustomerMilestones;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Money\Money;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.base_currency', 'USD' );

    $this->customer = Customer::factory()->create();
    $this->fired    = [];

    addAction( 'ap.ecommerce.customer.firstOrder', function ( Customer $customer, Order $order ): void {
        $this->fired[] = [ 'firstOrder', $customer->id, $order->id ];
    } );
    addAction( 'ap.ecommerce.customer.becameVip', function ( Customer $customer, string $reason ): void {
        $this->fired[] = [ 'becameVip', $customer->id, $reason ];
    } );
} );

/**
 * Marks a new order for the test customer paid and fires order.paid for it.
 *
 * @param  array<string, mixed>  $attributes
 */
function milestonePaidOrder( $test, array $attributes = [] ): Order
{
    $order = Order::factory()->create( array_merge( [
        'customer_id'    => $test->customer->id,
        'payment_status' => 'paid',
        'system_status'  => 'processing',
    ], $attributes ) );

    doAction( 'ap.ecommerce.order.paid', $order, PaymentResult::success( Money::USD( (int) $order->total_amount ), 'ch_test' ) );

    return $order;
}

it( 'fires customer.firstOrder on the first paid order only', function (): void {
    $first = milestonePaidOrder( $this );
    milestonePaidOrder( $this );

    expect( $this->fired )->toBe( [ [ 'firstOrder', $this->customer->id, $first->id ] ] );
    expect( $this->customer->fresh()->meta[ TrackCustomerMilestones::FIRST_ORDER_META_KEY ] )->toBe( $first->id );
} );

it( 'fires customer.firstOrder at most once when order.paid repeats for the same order', function (): void {
    $order = milestonePaidOrder( $this );

    doAction( 'ap.ecommerce.order.paid', $order, PaymentResult::success( Money::USD( 5_000 ), 'ch_test' ) );

    expect( $this->fired )->toHaveCount( 1 );
} );

it( 'does not fire customer.firstOrder when the customer already had a paid order before tracking began', function (): void {
    $earlier = Order::factory()->create( [ 'customer_id' => $this->customer->id, 'payment_status' => 'paid' ] );

    milestonePaidOrder( $this );

    expect( $this->fired )->toBe( [] );
    expect( $this->customer->fresh()->meta[ TrackCustomerMilestones::FIRST_ORDER_META_KEY ] )->toBe( $earlier->id );
} );

it( 'ignores guest orders', function (): void {
    $order = Order::factory()->create( [ 'customer_id' => null, 'payment_status' => 'paid' ] );

    doAction( 'ap.ecommerce.order.paid', $order, PaymentResult::success( Money::USD( 5_000 ), 'ch_test' ) );

    expect( $this->fired )->toBe( [] );
} );

it( 'never fires customer.becameVip while both thresholds are off', function (): void {
    foreach ( range( 1, 5 ) as $ignored ) {
        milestonePaidOrder( $this, [ 'total_amount' => 1_000_000 ] );
    }

    expect( collect( $this->fired )->where( 0, 'becameVip' ) )->toBeEmpty();
} );

it( 'fires customer.becameVip once when the paid-order count crosses the threshold', function (): void {
    config()->set( 'artisanpack.ecommerce.customers.vip.order_count', 3 );

    milestonePaidOrder( $this );
    milestonePaidOrder( $this );
    $third = milestonePaidOrder( $this );
    milestonePaidOrder( $this );

    expect( collect( $this->fired )->where( 0, 'becameVip' )->values()->all() )
        ->toBe( [ [ 'becameVip', $this->customer->id, TrackCustomerMilestones::REASON_ORDER_COUNT ] ] );
    expect( $this->customer->fresh()->meta[ TrackCustomerMilestones::VIP_META_KEY ] )
        ->toMatchArray( [ 'reason' => 'order_count', 'order_id' => $third->id ] );
} );

it( 'fires customer.becameVip when lifetime spend net of refunds crosses the threshold', function (): void {
    config()->set( 'artisanpack.ecommerce.customers.vip.lifetime_spend', 10_000 );

    milestonePaidOrder( $this, [ 'total_amount' => 6_000, 'total_refunded_amount' => 2_000, 'payment_status' => 'partially_refunded' ] );
    expect( collect( $this->fired )->where( 0, 'becameVip' ) )->toBeEmpty();

    milestonePaidOrder( $this, [ 'total_amount' => 6_000 ] );

    expect( collect( $this->fired )->where( 0, 'becameVip' )->values()->all() )
        ->toBe( [ [ 'becameVip', $this->customer->id, TrackCustomerMilestones::REASON_LIFETIME_SPEND ] ] );
} );

it( 'counts other-currency orders in the base currency at their snapshot rate', function (): void {
    config()->set( 'artisanpack.ecommerce.customers.vip.lifetime_spend', 10_000 );

    milestonePaidOrder( $this, [
        'currency'           => 'EUR',
        'base_currency'      => 'USD',
        'total_amount'       => 5_000,
        'fx_rate_to_base_e8' => 200_000_000,
    ] );

    expect( collect( $this->fired )->where( 0, 'becameVip' )->values()->all() )
        ->toBe( [ [ 'becameVip', $this->customer->id, TrackCustomerMilestones::REASON_LIFETIME_SPEND ] ] );
} );

it( 'logs instead of throwing when recording the milestones fails, so a captured checkout still succeeds', function (): void {
    $listener = new class ( app( 'config' ), app( ArtisanPackUI\Ecommerce\Services\CustomerStatsService::class ) ) extends TrackCustomerMilestones {
        protected function recordMilestones( Order $order ): array
        {
            throw new RuntimeException( 'database went away' );
        }
    };

    $order = Order::factory()->create( [ 'customer_id' => $this->customer->id, 'payment_status' => 'paid' ] );

    $listener->orderPaid( $order );

    expect( $this->fired )->toBe( [] );
} );
