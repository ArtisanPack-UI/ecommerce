<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Support\RateLimitPolicyRegistrar;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

it( 'registers every §16.1 policy with the rate limiter', function (): void {
    foreach ( RateLimitPolicyRegistrar::POLICIES as $policy ) {
        expect( RateLimiter::limiter( $policy ) )
            ->not->toBeNull( sprintf( 'policy "%s" was not registered', $policy ) );
    }
} );

it( 'resolves catalog.read with the documented per-IP default of 300/min', function (): void {
    $limits = ( RateLimiter::limiter( 'ecommerce.catalog.read' ) )( Request::create( '/x', 'GET' ) );

    expect( $limits )->toBeArray()->toHaveCount( 1 );
    expect( $limits[0] )->toBeInstanceOf( Limit::class );
    expect( $limits[0]->maxAttempts )->toBe( 300 );
    expect( $limits[0]->decaySeconds )->toBe( 60 );
} );

it( 'resolves checkout.finalize as a compound policy (per-IP + per-cart)', function (): void {
    $limits = ( RateLimiter::limiter( 'ecommerce.checkout.finalize' ) )( Request::create( '/x', 'POST' ) );

    expect( $limits )->toHaveCount( 2 );

    // Per-IP: 6/min
    expect( $limits[0]->maxAttempts )->toBe( 6 );
    expect( $limits[0]->decaySeconds )->toBe( 60 );

    // Per-cart: 12/hr
    expect( $limits[1]->maxAttempts )->toBe( 12 );
    expect( $limits[1]->decaySeconds )->toBe( 3_600 );
} );

it( 'resolves license.validate with 600/min per IP + 60/min per license', function (): void {
    // Pass the license key via the request body so the resolver's
    // `$request->input()` fallback fires without needing a bound route.
    $request = Request::create( '/licenses/validate', 'POST', [ 'license_key' => 'ABC123' ] );
    $limits  = ( RateLimiter::limiter( 'ecommerce.license.validate' ) )( $request );

    expect( $limits )->toHaveCount( 2 );
    expect( $limits[0]->maxAttempts )->toBe( 600 );
    expect( $limits[1]->maxAttempts )->toBe( 60 );
} );

it( 'resolves webhook.inbound with 1000/min per provider', function (): void {
    $limits = ( RateLimiter::limiter( 'ecommerce.webhook.inbound' ) )( Request::create( '/x', 'POST' ) );

    expect( $limits )->toHaveCount( 1 );
    expect( $limits[0]->maxAttempts )->toBe( 1_000 );
    expect( $limits[0]->decaySeconds )->toBe( 60 );
} );

it( 'honours per-policy config overrides', function (): void {
    config()->set( 'artisanpack.ecommerce.rate_limits.catalog.read.per_ip', 42 );

    RateLimitPolicyRegistrar::register();

    $limits = ( RateLimiter::limiter( 'ecommerce.catalog.read' ) )( Request::create( '/x', 'GET' ) );

    expect( $limits[0]->maxAttempts )->toBe( 42 );
} );

it( 'falls back to the shipped default when an override is non-positive', function (): void {
    config()->set( 'artisanpack.ecommerce.rate_limits.catalog.read.per_ip', 0 );

    RateLimitPolicyRegistrar::register();

    $limits = ( RateLimiter::limiter( 'ecommerce.catalog.read' ) )( Request::create( '/x', 'GET' ) );

    expect( $limits[0]->maxAttempts )->toBe( 300 );
} );

it( 'drops the per-email login bucket when the request carries no email', function (): void {
    $limits = ( RateLimiter::limiter( 'ecommerce.login' ) )( Request::create( '/login', 'POST' ) );

    // Only the per-IP bucket remains — the sha1( '' ) collapse cannot pool
    // every email-less caller into one shared 20/hr limit.
    expect( $limits )->toHaveCount( 1 );
    expect( $limits[0]->maxAttempts )->toBe( 5 );
} );

it( 'keeps both login buckets when the request carries an email', function (): void {
    $limits = ( RateLimiter::limiter( 'ecommerce.login' ) )( Request::create( '/login', 'POST', [ 'email' => 'a@b.c' ] ) );

    expect( $limits )->toHaveCount( 2 );
} );

it( 'drops the per-license bucket when the request carries no license key', function (): void {
    $limits = ( RateLimiter::limiter( 'ecommerce.license.validate' ) )(
        Request::create( '/licenses//validate', 'POST' ),
    );

    // Only the per-IP bucket remains for the same empty-subject reason.
    expect( $limits )->toHaveCount( 1 );
    expect( $limits[0]->maxAttempts )->toBe( 600 );
} );
