<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Support\RateLimitPolicyRegistrar;
use Illuminate\Support\Facades\Route;

beforeEach( function (): void {
    // Tight caps: 2/min per IP, 4/hr per cart. Both buckets should throttle
    // independently — hitting the IP cap must refuse even when a per-cart
    // bucket still has room, and vice versa.
    config()->set( 'artisanpack.ecommerce.rate_limits.checkout.finalize.per_ip', 2 );
    config()->set( 'artisanpack.ecommerce.rate_limits.checkout.finalize.per_cart', 4 );

    RateLimitPolicyRegistrar::register();

    Route::middleware( 'ecommerce.rate-limit:ecommerce.checkout.finalize' )->post(
        '/_test/checkout/finalize',
        fn () => response()->json( [ 'ok' => true ] ),
    );
} );

it( 'refuses when the per-IP bucket exhausts even if the per-cart bucket has room', function (): void {
    // Two fresh cart tokens per attempt so each per-cart bucket only sees
    // one hit — well under its 4/hr cap. The per-IP bucket is 2/min for
    // every request from this test's IP, so the third attempt must 429.
    $this->withHeader( 'X-Cart-Token', 'cart-a' )
        ->postJson( '/_test/checkout/finalize' )
        ->assertOk();

    $this->withHeader( 'X-Cart-Token', 'cart-b' )
        ->postJson( '/_test/checkout/finalize' )
        ->assertOk();

    $this->withHeader( 'X-Cart-Token', 'cart-c' )
        ->postJson( '/_test/checkout/finalize' )
        ->assertTooManyRequests();
} );

it( 'still refuses a specific cart when the per-cart bucket exhausts under a lax per-IP cap', function (): void {
    // Widen the per-IP cap so it cannot cause the refusal, then hit the
    // same cart five times — the fifth is over the 4/hr per-cart limit.
    config()->set( 'artisanpack.ecommerce.rate_limits.checkout.finalize.per_ip', 999 );

    RateLimitPolicyRegistrar::register();

    for ( $i = 0; $i < 4; $i++ ) {
        $this->withHeader( 'X-Cart-Token', 'cart-hot' )
            ->postJson( '/_test/checkout/finalize' )
            ->assertOk();
    }

    $this->withHeader( 'X-Cart-Token', 'cart-hot' )
        ->postJson( '/_test/checkout/finalize' )
        ->assertTooManyRequests();

    // A different cart on the same IP still passes — the per-cart bucket
    // is genuinely scoped by cart token.
    $this->withHeader( 'X-Cart-Token', 'cart-fresh' )
        ->postJson( '/_test/checkout/finalize' )
        ->assertOk();
} );

it( 'does not spend a sibling bucket when a compound policy refuses', function (): void {
    // With 2/min per IP and 4/hr per cart on a single token, we spend one
    // per-cart slot on each of the two allowed calls. The third call hits
    // the per-IP bucket first and is refused — a naïve check-and-hit loop
    // would still tick the per-cart counter, so a subsequent call from a
    // fresh IP with the same cart should still have 2 slots left.
    config()->set( 'artisanpack.ecommerce.rate_limits.checkout.finalize.per_ip', 2 );
    config()->set( 'artisanpack.ecommerce.rate_limits.checkout.finalize.per_cart', 4 );

    RateLimitPolicyRegistrar::register();

    $this->withHeader( 'X-Cart-Token', 'cart-shared' )
        ->postJson( '/_test/checkout/finalize' )
        ->assertOk();

    $this->withHeader( 'X-Cart-Token', 'cart-shared' )
        ->postJson( '/_test/checkout/finalize' )
        ->assertOk();

    $this->withHeader( 'X-Cart-Token', 'cart-shared' )
        ->postJson( '/_test/checkout/finalize' )
        ->assertTooManyRequests();

    // If the check-then-hit split works, the per-cart bucket has spent
    // exactly two slots and has two left. Simulate a fresh caller by
    // widening the per-IP cap so only the per-cart bucket can refuse.
    config()->set( 'artisanpack.ecommerce.rate_limits.checkout.finalize.per_ip', 999 );

    RateLimitPolicyRegistrar::register();

    $this->withHeader( 'X-Cart-Token', 'cart-shared' )
        ->postJson( '/_test/checkout/finalize' )
        ->assertOk();

    $this->withHeader( 'X-Cart-Token', 'cart-shared' )
        ->postJson( '/_test/checkout/finalize' )
        ->assertOk();

    $this->withHeader( 'X-Cart-Token', 'cart-shared' )
        ->postJson( '/_test/checkout/finalize' )
        ->assertTooManyRequests();
} );
