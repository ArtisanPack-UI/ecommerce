<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\ClaimRateLimitedException;
use ArtisanPackUI\Ecommerce\Exceptions\ClaimVerificationFailedException;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerClaimAttempt;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Services\CustomerClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->service = app( CustomerClaimService::class );
} );

it( 'claims a matching guest order and fires orderClaimed', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'buyer@example.com', 'user_id' => 10 ] );

    $order = Order::factory()->guest()->create( [
        'order_number'     => 'AP-1001',
        'email'            => 'buyer@example.com',
        'shipping_address' => [ 'postal_code' => '90210' ],
    ] );

    $captured = [];
    addAction( 'ap.ecommerce.customer.orderClaimed', function ( Customer $c, Order $o ) use ( &$captured ): void {
        $captured[] = [ $c->id, $o->id ];
    } );

    $claimed = $this->service->claim( $customer, 'AP-1001', '90210', '127.0.0.1' );

    expect( $claimed )->toHaveCount( 1 );
    expect( $claimed->first()->is_claimed )->toBeTrue();
    expect( $claimed->first()->customer_id )->toBe( $customer->id );
    expect( $captured )->toBe( [ [ $customer->id, $order->id ] ] );

    $attempt = CustomerClaimAttempt::query()->latest( 'id' )->first();
    expect( $attempt->was_success )->toBeTrue();
    expect( $attempt->order_number )->toBe( 'AP-1001' );
    expect( $attempt->ip_address )->toBe( '127.0.0.1' );
} );

it( 'retroactively claims every un-claimed order under the customers email on a single successful claim', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'multi@example.com' ] );

    Order::factory()->guest()->create( [
        'order_number'     => 'AP-1',
        'email'            => 'multi@example.com',
        'shipping_address' => [ 'postal_code' => '10001' ],
    ] );

    Order::factory()->guest()->create( [
        'order_number'     => 'AP-2',
        'email'            => 'multi@example.com',
        'shipping_address' => [ 'postal_code' => '99999' ],
    ] );

    $this->service->claim( $customer, 'AP-1', '10001' );

    expect( Order::query()->where( 'is_claimed', false )->count() )->toBe( 0 );
    expect( Order::query()->where( 'customer_id', $customer->id )->count() )->toBe( 2 );
} );

it( 'normalizes postal codes so casing and whitespace do not defeat the match', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'uk@example.com' ] );

    Order::factory()->guest()->create( [
        'order_number'     => 'AP-9',
        'email'            => 'uk@example.com',
        'shipping_address' => [ 'postal_code' => 'sw1a 1aa' ],
    ] );

    $claimed = $this->service->claim( $customer, 'AP-9', 'SW1A1AA' );

    expect( $claimed )->toHaveCount( 1 );
} );

it( 'records a failed attempt and throws when the postal code does not match', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'bad@example.com' ] );

    Order::factory()->guest()->create( [
        'order_number'     => 'AP-500',
        'email'            => 'bad@example.com',
        'shipping_address' => [ 'postal_code' => '00000' ],
    ] );

    expect( fn () => $this->service->claim( $customer, 'AP-500', '99999' ) )
        ->toThrow( ClaimVerificationFailedException::class );

    $attempt = CustomerClaimAttempt::query()->latest( 'id' )->first();
    expect( $attempt )->not()->toBeNull();
    expect( $attempt->was_success )->toBeFalse();

    expect( Order::query()->where( 'is_claimed', true )->count() )->toBe( 0 );
} );

it( 'throws ClaimRateLimitedException once the customer exhausts the window', function (): void {
    config()->set( 'artisanpack.ecommerce.customers.claim_rate_limit', 3 );
    config()->set( 'artisanpack.ecommerce.customers.claim_rate_window_minutes', 60 );

    $customer = Customer::factory()->create( [ 'email' => 'burst@example.com' ] );

    CustomerClaimAttempt::factory()
        ->for( $customer )
        ->count( 3 )
        ->create( [ 'created_at' => Carbon::now()->subMinutes( 5 ) ] );

    expect( fn () => $this->service->claim( $customer, 'AP-1', '90210' ) )
        ->toThrow( ClaimRateLimitedException::class );
} );

it( 'does not count successful attempts toward the failed-attempt rate limit', function (): void {
    config()->set( 'artisanpack.ecommerce.customers.claim_rate_limit', 3 );

    $customer = Customer::factory()->create( [ 'email' => 'won@example.com' ] );

    CustomerClaimAttempt::factory()
        ->for( $customer )
        ->success()
        ->count( 10 )
        ->create( [ 'created_at' => Carbon::now()->subMinutes( 5 ) ] );

    Order::factory()->guest()->create( [
        'order_number'     => 'AP-9001',
        'email'            => 'won@example.com',
        'shipping_address' => [ 'postal_code' => '90210' ],
    ] );

    $claimed = $this->service->claim( $customer, 'AP-9001', '90210' );

    expect( $claimed )->toHaveCount( 1 );
} );

it( 'refuses to reuse an already-claimed order as claim proof', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'reuse@example.com' ] );

    Order::factory()->create( [
        'order_number'     => 'AP-DONE',
        'email'            => 'reuse@example.com',
        'shipping_address' => [ 'postal_code' => '90210' ],
        'is_claimed'       => true,
    ] );

    expect( fn () => $this->service->claim( $customer, 'AP-DONE', '90210' ) )
        ->toThrow( ClaimVerificationFailedException::class );

    $attempt = CustomerClaimAttempt::query()->latest( 'id' )->first();
    expect( $attempt->was_success )->toBeFalse();
} );

it( 'ignores attempts older than the rate window', function (): void {
    config()->set( 'artisanpack.ecommerce.customers.claim_rate_limit', 3 );
    config()->set( 'artisanpack.ecommerce.customers.claim_rate_window_minutes', 60 );

    $customer = Customer::factory()->create( [ 'email' => 'aged@example.com' ] );

    CustomerClaimAttempt::factory()
        ->for( $customer )
        ->count( 5 )
        ->create( [ 'created_at' => Carbon::now()->subHours( 2 ) ] );

    Order::factory()->guest()->create( [
        'order_number'     => 'AP-77',
        'email'            => 'aged@example.com',
        'shipping_address' => [ 'postal_code' => '55555' ],
    ] );

    $claimed = $this->service->claim( $customer, 'AP-77', '55555' );

    expect( $claimed )->toHaveCount( 1 );
} );
