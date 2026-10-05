<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\RateLimitExceededException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\RateLimiting\EcommerceRateLimiter;
use ArtisanPackUI\Ecommerce\RateLimiting\RateLimitSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'limits coupon attempts per cart in-process, as the route does', function (): void {
    config()->set( 'artisanpack.ecommerce.rate_limits.coupon.attempt.per_cart', 2 );
    $cart    = Cart::factory()->create();
    $limiter = app( EcommerceRateLimiter::class );
    $runs    = 0;

    $limiter->attempt( 'ecommerce.coupon.attempt', RateLimitSubject::cart( $cart, '10.0.0.1' ), function () use ( &$runs ): void {
        ++$runs;
    } );
    $limiter->attempt( 'ecommerce.coupon.attempt', RateLimitSubject::cart( $cart, '10.0.0.2' ), function () use ( &$runs ): void {
        ++$runs;
    } );

    try {
        $limiter->attempt( 'ecommerce.coupon.attempt', RateLimitSubject::cart( $cart, '10.0.0.3' ), function () use ( &$runs ): void {
            ++$runs;
        } );
        $this->fail( 'The third attempt should have been refused.' );
    } catch ( RateLimitExceededException $exception ) {
        expect( $exception->policy )->toBe( 'ecommerce.coupon.attempt' )
            ->and( $exception->retryAfter )->toBeGreaterThan( 0 );
    }

    expect( $runs )->toBe( 2 );

    // Another cart has its own bucket.
    expect( $limiter->attempt( 'ecommerce.coupon.attempt', RateLimitSubject::cart( Cart::factory()->create(), '10.0.0.4' ), static fn (): string => 'ran' ) )->toBe( 'ran' );
} );

it( 'shares buckets with the REST routes', function (): void {
    config()->set( 'artisanpack.ecommerce.rate_limits.coupon.attempt.per_cart', 1 );
    $cart = Cart::factory()->create();

    app( EcommerceRateLimiter::class )->attempt( 'ecommerce.coupon.attempt', RateLimitSubject::cart( $cart ), static fn () => null );

    $this->postJson( "/api/ecommerce/v1/carts/{$cart->token}/coupons", [ 'code' => 'NOPE' ], [ 'Idempotency-Key' => 'k-' . uniqid() ] )
        ->assertStatus( 429 );
} );

it( 'limits reviews per customer', function (): void {
    config()->set( 'artisanpack.ecommerce.rate_limits.review.submit.per_customer', 1 );
    $customer = Customer::factory()->create();
    $limiter  = app( EcommerceRateLimiter::class );

    $limiter->attempt( 'ecommerce.review.submit', RateLimitSubject::customer( $customer, '10.0.0.9' ), static fn () => null );

    expect( fn () => $limiter->attempt( 'ecommerce.review.submit', RateLimitSubject::customer( $customer, '10.0.0.10' ), static fn () => null ) )
        ->toThrow( RateLimitExceededException::class );
} );

it( 'refuses an unknown policy', function (): void {
    app( EcommerceRateLimiter::class )->attempt( 'ecommerce.nope', RateLimitSubject::ip( '1.2.3.4' ), static fn () => null );
} )->throws( InvalidArgumentException::class, 'not registered' );
