<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Auth\ServiceSignature;
use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.api.services', [
        'erp-sync' => [ 'secret' => 'erp-shared-secret-value', 'abilities' => [ 'ecommerce:orders.read' ] ],
        'fulfil'   => [ 'secret' => 'fulfil-shared-secret', 'abilities' => [ 'ecommerce:orders.read', 'ecommerce:orders.write' ] ],
    ] );
} );

/**
 * Signed headers for a request against the test host.
 *
 * @return array<string, string>
 */
function serviceHeaders( string $method, string $uri, string $body = '', string $keyId = 'erp-sync', string $secret = 'erp-shared-secret-value', ?Carbon $date = null ): array
{
    return ServiceSignature::sign( $keyId, $secret, $method, $uri, 'localhost', $body, $date ) + [ 'Accept' => 'application/json' ];
}

it( 'authenticates a signed service request and grants its configured scopes', function (): void {
    Order::factory()->create();

    $this->withHeaders( serviceHeaders( 'GET', '/api/ecommerce/v1/orders' ) )
        ->get( '/api/ecommerce/v1/orders' )
        ->assertOk()
        ->assertJsonCount( 1, 'data' );
} );

it( 'denies a signed service request outside its scopes', function (): void {
    $this->withHeaders( serviceHeaders( 'GET', '/api/ecommerce/v1/customers' ) )
        ->get( '/api/ecommerce/v1/customers' )
        ->assertForbidden();
} );

it( 'keys idempotency records to the service', function (): void {
    $order = Order::factory()->create();
    $uri   = "/api/ecommerce/v1/orders/{$order->id}";
    $data  = [ 'customer_note' => 'Synced' ];

    $this->patchJson( $uri, $data, serviceHeaders( 'PATCH', $uri, (string) json_encode( $data ), 'fulfil', 'fulfil-shared-secret' ) + [ 'Idempotency-Key' => (string) Str::uuid() ] )
        ->assertOk()
        ->assertJsonPath( 'data.customer_note', 'Synced' );

    expect( IdempotencyRecord::query()->value( 'actor_scope' ) )->toBe( 'service:fulfil' );
} );

it( 'rejects a bad signature', function ( array $headers ): void {
    $this->withHeaders( $headers )
        ->get( '/api/ecommerce/v1/orders' )
        ->assertUnauthorized()
        ->assertHeader( 'Content-Type', 'application/problem+json' )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/invalid-service-signature' );
} )->with( [
    'unknown service' => fn () => serviceHeaders( 'GET', '/api/ecommerce/v1/orders', '', 'nobody' ),
    'wrong secret'    => fn () => serviceHeaders( 'GET', '/api/ecommerce/v1/orders', '', 'erp-sync', 'not-the-secret' ),
    'stale date'      => fn () => serviceHeaders( 'GET', '/api/ecommerce/v1/orders', '', 'erp-sync', 'erp-shared-secret-value', Carbon::now()->subMinutes( 10 ) ),
    'other path'      => fn () => serviceHeaders( 'GET', '/api/ecommerce/v1/customers' ),
    'body digest'     => fn () => array_replace( serviceHeaders( 'GET', '/api/ecommerce/v1/orders' ), [ 'Digest' => ServiceSignature::digest( 'tampered' ) ] ),
    'wrong algorithm' => fn () => array_replace( serviceHeaders( 'GET', '/api/ecommerce/v1/orders' ), [
        'Authorization' => str_replace( 'hmac-sha256', 'hmac-sha1', serviceHeaders( 'GET', '/api/ecommerce/v1/orders' )['Authorization'] ),
    ] ),
    'relative date'   => fn () => ( function (): array {
        $headers         = ServiceSignature::sign( 'erp-sync', 'erp-shared-secret-value', 'GET', '/api/ecommerce/v1/orders', 'localhost', '' );
        $headers['Date'] = 'now';

        return $headers;
    } )(),
    'unsafe keyId'           => fn () => serviceHeaders( 'GET', '/api/ecommerce/v1/orders', '', 'erp-sync.secret' ),
    'missing covered header' => fn () => array_replace( serviceHeaders( 'GET', '/api/ecommerce/v1/orders' ), [
        'Authorization' => str_replace( ' digest"', '"', serviceHeaders( 'GET', '/api/ecommerce/v1/orders' )['Authorization'] ),
    ] ),
] );

it( 'rejects a replayed signature', function (): void {
    $headers = serviceHeaders( 'GET', '/api/ecommerce/v1/orders' );

    $this->withHeaders( $headers )->get( '/api/ecommerce/v1/orders' )->assertOk();
    $this->withHeaders( $headers )->get( '/api/ecommerce/v1/orders' )->assertUnauthorized();
} );

it( 'leaves non-signature requests to the regular auth stack', function (): void {
    $this->withHeaders( [ 'Authorization' => 'Basic bm9wZTpub3Bl', 'Accept' => 'application/json' ] )
        ->get( '/api/ecommerce/v1/orders' )
        ->assertUnauthorized()
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/unauthenticated' );
} );

it( 'parses only Signature authorization headers', function (): void {
    expect( ServiceSignature::parse( 'Bearer abc' ) )->toBeNull()
        ->and( ServiceSignature::parse( null ) )->toBeNull()
        ->and( ServiceSignature::parse( 'Signature keyId="a",algorithm="hmac-sha256",headers="date",signature="xyz="' ) )
        ->toBe( [ 'keyId' => 'a', 'algorithm' => 'hmac-sha256', 'headers' => 'date', 'signature' => 'xyz=' ] );
} );
