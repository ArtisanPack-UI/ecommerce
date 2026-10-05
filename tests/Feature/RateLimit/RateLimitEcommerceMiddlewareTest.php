<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Http\Middleware\RateLimitEcommerce;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach( function (): void {
    // Tight, easy-to-exhaust override so a handful of requests trips the bucket.
    config()->set( 'artisanpack.ecommerce.rate_limits.catalog.read.per_ip', 2 );

    ArtisanPackUI\Ecommerce\Support\RateLimitPolicyRegistrar::register();

    Route::middleware( 'ecommerce.rate-limit:ecommerce.catalog.read' )->get(
        '/_test/catalog',
        fn () => response()->json( [ 'ok' => true ] ),
    );
} );

it( 'allows requests below the configured limit', function (): void {
    $this->getJson( '/_test/catalog' )->assertOk();
    $this->getJson( '/_test/catalog' )->assertOk();
} );

it( 'returns problem+json with retry_after when the limit is exceeded', function (): void {
    $this->getJson( '/_test/catalog' )->assertOk();
    $this->getJson( '/_test/catalog' )->assertOk();

    $response = $this->getJson( '/_test/catalog' );

    $response->assertTooManyRequests();
    expect( $response->headers->get( 'Content-Type' ) )
        ->toStartWith( RateLimitEcommerce::PROBLEM_CONTENT_TYPE );

    $response->assertJsonPath( 'status', 429 );
    $response->assertJsonPath( 'policy', 'ecommerce.catalog.read' );

    expect( $response->json( 'retry_after' ) )->toBeInt()->toBeGreaterThanOrEqual( 0 );
    expect( $response->json( 'type' ) )->toEndWith( '/rate-limited' );

    expect( $response->headers->get( 'Retry-After' ) )->not->toBeNull();
    expect( $response->headers->get( 'X-RateLimit-Limit' ) )->toBe( '2' );
    expect( $response->headers->get( 'X-RateLimit-Remaining' ) )->toBe( '0' );
} );

it( 'emits a structured warning log entry on rate-limit hits', function (): void {
    Log::spy();

    $this->getJson( '/_test/catalog' )->assertOk();
    $this->getJson( '/_test/catalog' )->assertOk();
    $this->getJson( '/_test/catalog' )->assertTooManyRequests();

    Log::shouldHaveReceived( 'warning' )
        ->withArgs( function ( string $message, array $context ): bool {
            return 'ecommerce.rate_limit.exceeded' === $message
                && 'ecommerce.catalog.read' === ( $context['policy'] ?? null )
                && 2 === ( $context['limit'] ?? null )
                && array_key_exists( 'retry_after', $context )
                && array_key_exists( 'ip_hash', $context )
                && array_key_exists( 'route', $context )
                && array_key_exists( 'uri', $context );
        } )
        ->once();
} );

it( 'never logs the raw path (license keys and other in-URL tokens stay out of logs)', function (): void {
    config()->set( 'artisanpack.ecommerce.rate_limits.license.validate.per_ip', 1 );
    config()->set( 'artisanpack.ecommerce.rate_limits.license.validate.per_license', 5 );

    ArtisanPackUI\Ecommerce\Support\RateLimitPolicyRegistrar::register();

    Route::middleware( 'ecommerce.rate-limit:ecommerce.license.validate' )->post(
        '/_test/licenses/{license_key}/validate',
        fn () => response()->json( [ 'ok' => true ] ),
    );

    Log::spy();

    $this->postJson( '/_test/licenses/SECRET-KEY-123/validate' )->assertOk();
    $this->postJson( '/_test/licenses/SECRET-KEY-123/validate' )->assertTooManyRequests();

    Log::shouldHaveReceived( 'warning' )
        ->withArgs( function ( string $message, array $context ): bool {
            if ( 'ecommerce.rate_limit.exceeded' !== $message ) {
                return false;
            }

            $serialized = json_encode( $context );

            return false !== $serialized && ! str_contains( $serialized, 'SECRET-KEY-123' );
        } )
        ->once();
} );

it( 'throws when applied with an unregistered policy', function (): void {
    Route::middleware( 'ecommerce.rate-limit:ecommerce.does-not-exist' )->get(
        '/_test/unregistered',
        fn () => 'ok',
    );

    $this->withoutExceptionHandling();

    expect( fn () => $this->getJson( '/_test/unregistered' ) )
        ->toThrow( InvalidArgumentException::class );
} );
