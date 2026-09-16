<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Http\Middleware\IdempotencyMiddleware;
use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Route::middleware( IdempotencyMiddleware::class )->post(
        '/_test/idempotent-echo',
        function ( Illuminate\Http\Request $request ) {
            return response()->json( [
                'ok'      => true,
                'payload' => $request->all(),
                'nonce'   => uniqid( 'n_', true ),
            ], 201 );
        },
    )->name( 'test.idempotent.echo' );
} );

it( 'returns 400 problem+json when the Idempotency-Key header is missing', function (): void {
    $response = $this->postJson( '/_test/idempotent-echo', [ 'quantity' => 1 ] );

    $response->assertStatus( 400 );
    expect( $response->headers->get( 'Content-Type' ) )
        ->toStartWith( 'application/problem+json' );
    $response->assertJsonPath( 'status', 400 );
    $response->assertJsonPath( 'title', 'Idempotency-Key header is required' );
    expect( $response->json( 'type' ) )->toContain( 'missing-idempotency-key' );

    expect( IdempotencyRecord::query()->count() )->toBe( 0 );
} );

it( 'runs the endpoint and stores the terminal response on first call', function (): void {
    $response = $this->withHeader( 'Idempotency-Key', 'key-1' )
        ->postJson( '/_test/idempotent-echo', [ 'quantity' => 2 ] );

    $response->assertStatus( 201 );
    expect( $response->headers->get( IdempotencyMiddleware::REPLAY_HEADER ) )->toBeNull();

    $record = IdempotencyRecord::query()->firstOrFail();
    expect( $record->idempotency_key )->toBe( 'key-1' );
    expect( $record->endpoint_key )->toBe( 'test.idempotent.echo' );
    expect( $record->response_status )->toBe( 201 );
    expect( $record->locked_at )->toBeNull();
    expect( $record->response_body )->toBe( $response->getContent() );
} );

it( 'replays the stored response byte-for-byte on the second call with the same key + payload', function (): void {
    $first = $this->withHeader( 'Idempotency-Key', 'key-replay' )
        ->postJson( '/_test/idempotent-echo', [ 'quantity' => 3 ] );

    $second = $this->withHeader( 'Idempotency-Key', 'key-replay' )
        ->postJson( '/_test/idempotent-echo', [ 'quantity' => 3 ] );

    $second->assertStatus( 201 );
    expect( $second->getContent() )->toBe( $first->getContent() );
    expect( $second->headers->get( IdempotencyMiddleware::REPLAY_HEADER ) )->toBe( 'true' );
    // The endpoint generates a unique nonce per call — replay proves it wasn't re-executed.
    expect( $second->json( 'nonce' ) )->toBe( $first->json( 'nonce' ) );

    expect( IdempotencyRecord::query()->count() )->toBe( 1 );
} );

it( 'returns 409 problem+json when the same key is reused with a different payload', function (): void {
    $this->withHeader( 'Idempotency-Key', 'key-conflict' )
        ->postJson( '/_test/idempotent-echo', [ 'quantity' => 1 ] )
        ->assertStatus( 201 );

    $conflict = $this->withHeader( 'Idempotency-Key', 'key-conflict' )
        ->postJson( '/_test/idempotent-echo', [ 'quantity' => 999 ] );

    $conflict->assertStatus( 409 );
    expect( $conflict->headers->get( 'Content-Type' ) )
        ->toStartWith( 'application/problem+json' );
    $conflict->assertJsonPath( 'status', 409 );
    expect( $conflict->json( 'type' ) )->toContain( 'idempotency-key-conflict' );
} );

it( 'ignores key ordering when hashing the payload', function (): void {
    $this->withHeader( 'Idempotency-Key', 'key-order' )
        ->postJson( '/_test/idempotent-echo', [ 'a' => 1, 'b' => 2 ] )
        ->assertStatus( 201 );

    $second = $this->withHeader( 'Idempotency-Key', 'key-order' )
        ->postJson( '/_test/idempotent-echo', [ 'b' => 2, 'a' => 1 ] );

    $second->assertStatus( 201 );
    expect( $second->headers->get( IdempotencyMiddleware::REPLAY_HEADER ) )->toBe( 'true' );
} );

it( 'returns 409 when an in-flight lock does not release inside wait_ms', function (): void {
    config()->set( 'artisanpack.ecommerce.idempotency.wait_ms', 50 );
    config()->set( 'artisanpack.ecommerce.idempotency.poll_ms', 10 );

    $requestHash = hash(
        'sha256',
        json_encode(
            [ 'body' => [ 'quantity' => 5 ], 'query' => [] ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ),
    );

    IdempotencyRecord::query()->create( [
        'actor_scope'     => 'ip:127.0.0.1',
        'endpoint_key'    => 'test.idempotent.echo',
        'idempotency_key' => 'key-inflight',
        'request_hash'    => $requestHash,
        'locked_at'       => Carbon::now(),
        'expires_at'      => Carbon::now()->addHour(),
    ] );

    $response = $this->withHeader( 'Idempotency-Key', 'key-inflight' )
        ->postJson( '/_test/idempotent-echo', [ 'quantity' => 5 ] );

    $response->assertStatus( 409 );
} );

it( 'does not crash when the authenticated user lacks Sanctum HasApiTokens', function (): void {
    // A User model that has no `currentAccessToken()` method must fall back
    // to `user:{id}` scope, not fatal with BadMethodCallException.
    $user = new class extends Illuminate\Foundation\Auth\User {
        public function getAuthIdentifier()
        {
            return 42;
        }
    };

    $this->actingAs( $user )
        ->withHeader( 'Idempotency-Key', 'key-no-sanctum' )
        ->postJson( '/_test/idempotent-echo', [ 'x' => 1 ] )
        ->assertStatus( 201 );

    $record = IdempotencyRecord::query()->firstOrFail();
    expect( $record->actor_scope )->toBe( 'user:42' );
} );

it( 'does not persist Set-Cookie into response_headers', function (): void {
    Route::middleware( IdempotencyMiddleware::class )->post(
        '/_test/idempotent-cookie',
        function () {
            return response()->json( [ 'ok' => true ], 201 )
                ->withCookie( cookie( 'session_marker', 'abc', 60 ) );
        },
    )->name( 'test.idempotent.cookie' );

    $this->withHeader( 'Idempotency-Key', 'key-cookie' )
        ->postJson( '/_test/idempotent-cookie' )
        ->assertStatus( 201 );

    $record = IdempotencyRecord::query()
        ->where( 'idempotency_key', 'key-cookie' )
        ->firstOrFail();

    $headers = array_change_key_case( (array) $record->response_headers, CASE_LOWER );
    expect( array_key_exists( 'set-cookie', $headers ) )->toBeFalse();

    $replay = $this->withHeader( 'Idempotency-Key', 'key-cookie' )
        ->postJson( '/_test/idempotent-cookie' );

    expect( $replay->headers->get( IdempotencyMiddleware::REPLAY_HEADER ) )->toBe( 'true' );
    expect( $replay->headers->getCookies() )->toBe( [] );
} );

it( 'uses the per-endpoint TTL override when configured', function (): void {
    config()->set(
        'artisanpack.ecommerce.idempotency.ttls',
        [ 'test.idempotent.echo' => 1 ],
    );

    Carbon::setTestNow( '2026-06-01 00:00:00' );

    $this->withHeader( 'Idempotency-Key', 'key-ttl' )
        ->postJson( '/_test/idempotent-echo', [ 'quantity' => 1 ] )
        ->assertStatus( 201 );

    $record = IdempotencyRecord::query()->firstOrFail();
    expect( $record->expires_at->toDateTimeString() )->toBe( '2026-06-01 01:00:00' );

    Carbon::setTestNow();
} );
