<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Listeners\UpdateCustomerStats;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\CustomerStatsService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/../Checkout/CheckoutTestHelpers.php';

uses( RefreshDatabase::class );

it( 'counts a paid checkout in the customer\'s stats (D15)', function (): void {
    $gateway  = checkoutGateway();
    checkoutZone();
    $customer = Customer::factory()->create();
    $cart     = app( StorefrontCartService::class )->create( 'USD', null, $customer );
    $cart     = readyCart( checkoutProduct(), 1, $cart );
    $session  = app( CheckoutService::class )->createPaymentSession( $cart );
    $gateway->confirm( $session->reference );

    app( CheckoutService::class )->finalize( $cart, $session->reference );

    $customer->refresh();

    expect( $customer->orders_count )->toBe( 1 )
        ->and( $customer->total_spent_amount )->toBe( 2_500 )
        ->and( $customer->total_spent_currency )->toBe( 'USD' )
        ->and( $customer->last_ordered_at )->not->toBeNull();
} );

it( 'takes refunds and cancellations off, and adds claimed orders', function (): void {
    $customer = Customer::factory()->create();
    $order    = Order::factory()->create( [ 'customer_id' => $customer->id, 'payment_status' => 'paid', 'total_amount' => 10_000, 'placed_at' => Carbon::now()->subDay() ] );

    doAction( 'ap.ecommerce.order.paid', $order );

    expect( $customer->refresh()->total_spent_amount )->toBe( 10_000 );

    $order->forceFill( [ 'payment_status' => 'partially_refunded', 'total_refunded_amount' => 4_000 ] )->save();
    app( UpdateCustomerStats::class )->forOrder( $order );

    expect( $customer->refresh()->total_spent_amount )->toBe( 6_000 )
        ->and( $customer->orders_count )->toBe( 1 );

    $order->forceFill( [ 'payment_status' => 'refunded', 'total_refunded_amount' => 10_000 ] )->save();
    app( UpdateCustomerStats::class )->forOrder( $order );

    expect( $customer->refresh()->total_spent_amount )->toBe( 0 )
        ->and( $customer->orders_count )->toBe( 0 );

    $claimed = Order::factory()->create( [ 'customer_id' => $customer->id, 'payment_status' => 'paid', 'total_amount' => 3_000, 'placed_at' => Carbon::now() ] );
    app( UpdateCustomerStats::class )->forCustomer( $customer );

    expect( $customer->refresh()->total_spent_amount )->toBe( 3_000 )
        ->and( $customer->last_ordered_at->toDateString() )->toBe( Carbon::now()->toDateString() );
} );

it( 'is idempotent', function (): void {
    $customer = Customer::factory()->create();
    Order::factory()->count( 2 )->create( [ 'customer_id' => $customer->id, 'payment_status' => 'paid', 'total_amount' => 1_000 ] );

    $stats = app( CustomerStatsService::class );
    $stats->recalculate( $customer );
    $stats->recalculate( $customer );

    expect( $customer->refresh()->orders_count )->toBe( 2 )
        ->and( $customer->total_spent_amount )->toBe( 2_000 );
} );
