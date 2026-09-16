<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Http\Middleware\RequestIdMiddleware;
use ArtisanPackUI\Ecommerce\Support\RequestContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach( function (): void {
    $this->logPath = storage_path( 'logs/ecommerce.log' );

    if ( file_exists( $this->logPath ) ) {
        unlink( $this->logPath );
    }

    Route::middleware( RequestIdMiddleware::class )->get(
        '/_test/request-id',
        function (): array {
            Log::channel( 'ecommerce' )->info( 'ping', [ 'event' => 'test.ping' ] );

            return [
                'held_request_id' => RequestContext::requestId(),
            ];
        },
    );
} );

afterEach( function (): void {
    RequestContext::reset();

    if ( file_exists( $this->logPath ) ) {
        unlink( $this->logPath );
    }
} );

it( 'echoes back an incoming X-Request-Id header on the response', function (): void {
    $response = $this->withHeader( 'X-Request-Id', 'req-inbound-123' )
        ->getJson( '/_test/request-id' );

    $response->assertOk();
    expect( $response->headers->get( 'X-Request-Id' ) )->toBe( 'req-inbound-123' );
    expect( $response->json( 'held_request_id' ) )->toBe( 'req-inbound-123' );
} );

it( 'generates a UUID request id when the inbound header is missing', function (): void {
    $response = $this->getJson( '/_test/request-id' );

    $response->assertOk();
    $generated = $response->headers->get( 'X-Request-Id' );

    expect( $generated )->toMatch( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/' );
    expect( $response->json( 'held_request_id' ) )->toBe( $generated );
} );

it( 'generates a UUID when the inbound header contains invalid characters', function (): void {
    $response = $this->withHeader( 'X-Request-Id', "bad\nid" )
        ->getJson( '/_test/request-id' );

    $response->assertOk();
    $generated = $response->headers->get( 'X-Request-Id' );

    expect( $generated )->not->toBe( "bad\nid" );
    expect( $generated )->toMatch( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/' );
} );

it( 'generates a UUID when the inbound header is longer than the accepted bound', function (): void {
    $tooLong = str_repeat( 'a', RequestIdMiddleware::MAX_LENGTH + 1 );

    $response = $this->withHeader( 'X-Request-Id', $tooLong )
        ->getJson( '/_test/request-id' );

    $response->assertOk();
    expect( $response->headers->get( 'X-Request-Id' ) )->not->toBe( $tooLong );
} );

it( 'trims surrounding whitespace from the inbound header before echoing it back', function (): void {
    $response = $this->withHeader( 'X-Request-Id', '  req-padded  ' )
        ->getJson( '/_test/request-id' );

    $response->assertOk();
    expect( $response->headers->get( 'X-Request-Id' ) )->toBe( 'req-padded' );
} );

it( 'threads the request id into log context so ecommerce log lines carry it', function (): void {
    $this->withHeader( 'X-Request-Id', 'req-logged-999' )
        ->getJson( '/_test/request-id' )
        ->assertOk();

    expect( file_exists( $this->logPath ) )->toBeTrue();

    $line    = trim( file_get_contents( $this->logPath ) );
    $payload = json_decode( $line, true, flags: JSON_THROW_ON_ERROR );

    expect( $payload['request_id'] )->toBe( 'req-logged-999' );
    expect( $payload['event'] )->toBe( 'test.ping' );
} );

it( 'clears the RequestContext holder after the request completes', function (): void {
    $this->withHeader( 'X-Request-Id', 'req-cleanup' )
        ->getJson( '/_test/request-id' )
        ->assertOk();

    expect( RequestContext::requestId() )->toBeNull();
} );

it( 'aliases the middleware as `ecommerce.request-id`', function (): void {
    Route::middleware( 'ecommerce.request-id' )->get(
        '/_test/aliased-request-id',
        fn () => [ 'held' => RequestContext::requestId() ],
    );

    $response = $this->withHeader( 'X-Request-Id', 'req-aliased-1' )
        ->getJson( '/_test/aliased-request-id' );

    $response->assertOk();
    expect( $response->headers->get( 'X-Request-Id' ) )->toBe( 'req-aliased-1' );
    expect( $response->json( 'held' ) )->toBe( 'req-aliased-1' );
} );
