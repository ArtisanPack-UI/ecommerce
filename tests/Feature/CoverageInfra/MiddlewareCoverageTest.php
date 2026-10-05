<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Auth\ServiceActor;
use ArtisanPackUI\Ecommerce\Auth\ServiceSignature;
use ArtisanPackUI\Ecommerce\Http\Middleware\AuthenticateOptionally;
use ArtisanPackUI\Ecommerce\Http\Middleware\ForceJsonResponse;
use ArtisanPackUI\Ecommerce\Http\Middleware\LimitGraphQLBatch;
use ArtisanPackUI\Ecommerce\Http\Middleware\ServiceSignatureMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\ApiUser;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.api.services', [
        'ci-erp' => [ 'secret' => 'ci-erp-secret-value', 'abilities' => [ 'ecommerce:orders.read' ] ],
    ] );

    $who = static fn ( Request $request ): array => [
        'user'  => $request->user()?->getAuthIdentifier(),
        'actor' => $request->user() instanceof ServiceActor,
    ];

    Route::match( [ 'GET', 'POST' ], '/_ci/signed', $who )->middleware( ServiceSignatureMiddleware::class );
    Route::get( '/_ci/optional', $who )->middleware( AuthenticateOptionally::class );
    Route::get( '/_ci/json', static fn () => abort( 404 ) )->middleware( ForceJsonResponse::class );
} );

/**
 * Runs `$middleware` on `$request`, answering `ok` from the next layer.
 */
function ciPass( object $middleware, Request $request ): Symfony\Component\HttpFoundation\Response
{
    return $middleware->handle( $request, static fn (): Response => new Response( 'ok' ) );
}

/**
 * Signed headers for the throwaway route.
 *
 * @return array<string, string>
 */
function ciSigned( string $method = 'GET', string $body = '', string $secret = 'ci-erp-secret-value', ?Carbon $date = null ): array
{
    return ServiceSignature::sign( 'ci-erp', $secret, $method, '/_ci/signed', 'localhost', $body, $date ) + [ 'Accept' => 'application/json' ];
}

it( 'lets single GraphQL operations and batches up to the limit through', function ( mixed $payload ): void {
    config()->set( 'artisanpack.ecommerce.graphql.max_batch', 3 );
    $request = Request::create( '/graphql/ecommerce', 'POST', [], [], [], [ 'CONTENT_TYPE' => 'application/json' ], (string) json_encode( $payload ) );

    expect( ciPass( new LimitGraphQLBatch(), $request )->getContent() )->toBe( 'ok' );
} )->with( [
    'single'      => [ [ 'query' => '{ a }' ] ],
    'batch of 3'  => [ array_fill( 0, 3, [ 'query' => '{ a }' ] ) ],
    'empty body'  => [ [] ],
] );

it( 'refuses a GraphQL batch over the limit, including multipart operations', function (): void {
    config()->set( 'artisanpack.ecommerce.graphql.max_batch', 2 );

    $json = Request::create( '/graphql/ecommerce', 'POST', [], [], [], [ 'CONTENT_TYPE' => 'application/json' ], (string) json_encode( array_fill( 0, 3, [ 'query' => '{ a }' ] ) ) );
    $form = Request::create( '/graphql/ecommerce', 'POST', [ 'operations' => (string) json_encode( array_fill( 0, 3, [ 'query' => '{ a }' ] ) ) ] );

    foreach ( [ $json, $form ] as $request ) {
        $response = ciPass( new LimitGraphQLBatch(), $request );

        expect( $response->getStatusCode() )->toBe( 400 )
            ->and( (string) $response->getContent() )->toContain( 'graphql-batch-too-large' );
    }
} );

it( 'passes unsigned requests through untouched', function (): void {
    $this->getJson( '/_ci/signed' )->assertOk()->assertJsonPath( 'user', null );
} );

it( 'authenticates a correctly signed request as the service', function (): void {
    $this->withHeaders( ciSigned() )->get( '/_ci/signed' )->assertOk()->assertJsonPath( 'user', 'service:ci-erp' )->assertJsonPath( 'actor', true );

    $body = '{"x":1}';
    $this->call( 'POST', '/_ci/signed', [], [], [], array_combine(
        array_map( static fn ( string $name ): string => 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) ), array_keys( ciSigned( 'POST', $body ) ) ),
        array_values( ciSigned( 'POST', $body ) ),
    ) + [ 'CONTENT_TYPE' => 'application/json' ], $body )->assertOk()->assertJsonPath( 'actor', true );
} );

it( 'refuses a replay of the same signed request', function (): void {
    $headers = ciSigned();

    $this->withHeaders( $headers )->get( '/_ci/signed' )->assertOk();
    $this->withHeaders( $headers )->get( '/_ci/signed' )->assertUnauthorized()->assertJsonPath( 'type', fn ( string $type ): bool => str_ends_with( $type, 'invalid-service-signature' ) );
} );

it( 'refuses expired, future, wrongly keyed, and tampered signatures', function ( array $headers ): void {
    $this->withHeaders( $headers )->get( '/_ci/signed' )->assertUnauthorized();
} )->with( [
    'expired'      => fn (): array => ciSigned( date: Carbon::now()->subMinutes( 6 ) ),
    'future'       => fn (): array => ciSigned( date: Carbon::now()->addMinutes( 6 ) ),
    'wrong secret' => fn (): array => ciSigned( secret: 'not-the-secret' ),
    'tampered'     => fn (): array => array_replace( ciSigned(), [ 'Digest' => ServiceSignature::digest( 'other body' ) ] ),
] );

it( 'honours a shorter tolerance', function (): void {
    config()->set( 'artisanpack.ecommerce.api.signature_tolerance_seconds', 30 );

    $this->withHeaders( ciSigned( date: Carbon::now()->subMinute() ) )->get( '/_ci/signed' )->assertUnauthorized();
} );

it( 'resolves a Sanctum user on otherwise public routes, and nobody without one', function (): void {
    $this->getJson( '/_ci/optional' )->assertOk()->assertJsonPath( 'user', null );

    Sanctum::actingAs( ApiUser::make( 7 ), [ '*' ] );

    $this->getJson( '/_ci/optional' )->assertOk()->assertJsonPath( 'user', 7 );
} );

it( 'skips Sanctum when its guard is not configured', function (): void {
    config()->set( 'auth.guards.sanctum', null );
    $request = Request::create( '/_ci/optional' );

    $response = ( new AuthenticateOptionally() )->handle( $request, static fn ( Request $passed ): Response => new Response( null === $passed->user() ? 'guest' : 'user' ) );

    expect( $response->getContent() )->toBe( 'guest' );
} );

it( 'forces JSON responses whatever the client accepts', function (): void {
    $request = Request::create( '/x', 'GET', [], [], [], [ 'HTTP_ACCEPT' => 'text/html' ] );

    ( new ForceJsonResponse() )->handle( $request, static fn ( Request $passed ): Response => new Response( $passed->header( 'Accept' ) ) );

    expect( $request->expectsJson() )->toBeTrue();

    $this->get( '/_ci/json', [ 'Accept' => 'text/html' ] )
        ->assertNotFound()
        ->assertHeader( 'Content-Type', 'application/json' );
} );
