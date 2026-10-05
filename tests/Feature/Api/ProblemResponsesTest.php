<?php

declare( strict_types=1 );

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'answers missing resources with a problem that names no class (F5)', function ( string $uri ): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $response = $this->getJson( $uri )
        ->assertNotFound()
        ->assertHeader( 'Content-Type', 'application/problem+json' );

    expect( $response->json( 'type' ) )->toEndWith( '/not-found' )
        ->and( $response->getContent() )->not->toContain( 'ArtisanPackUI' );
} )->with( [
    'model'         => [ '/api/ecommerce/v1/orders/999999' ],
    'unknown cart'  => [ '/api/ecommerce/v1/carts/' . str_repeat( 'a', 40 ) ],
    'unknown route' => [ '/api/ecommerce/v1/nothing-here' ],
] );

it( 'answers a wrong method with 405 and an unexpected error with a bare 500', function (): void {
    $this->deleteJson( '/api/ecommerce/v1/products' )
        ->assertStatus( 405 )
        ->assertHeader( 'Content-Type', 'application/problem+json' )
        ->assertJsonPath( 'type', fn ( string $type ) => str_ends_with( $type, '/method-not-allowed' ) );

    config()->set( 'app.debug', false );
    Route::get( 'api/ecommerce/v1/__boom', static fn () => throw new RuntimeException( 'secret detail' ) )->name( 'ecommerce.api.boom' );

    $response = $this->getJson( '/api/ecommerce/v1/__boom' )->assertStatus( 500 )->assertHeader( 'Content-Type', 'application/problem+json' );

    expect( $response->getContent() )->not->toContain( 'secret detail' );
} );

it( 'names the failed rule in each validation error code (F7)', function (): void {
    $cart = ArtisanPackUI\Ecommerce\Models\Cart::factory()->create();

    $this->postJson( "/api/ecommerce/v1/carts/{$cart->token}/items", [], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'product_id' )
        ->assertJsonPath( 'errors.0.code', 'required' );

    $this->postJson( "/api/ecommerce/v1/carts/{$cart->token}/items", [ 'product_id' => 1, 'quantity' => 20_000 ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'quantity' )
        ->assertJsonPath( 'errors.0.code', 'max' );
} );

it( 'makes guests use a strong idempotency key and never replays a guest cart token (F10)', function (): void {
    $this->postJson( '/api/ecommerce/v1/carts', [], [ 'Idempotency-Key' => 'abc' ] )
        ->assertStatus( 400 )
        ->assertJsonPath( 'type', fn ( string $type ) => str_ends_with( $type, '/weak-idempotency-key' ) );

    $key   = (string) Illuminate\Support\Str::uuid();
    $first = $this->postJson( '/api/ecommerce/v1/carts', [], [ 'Idempotency-Key' => $key ] )->assertCreated();

    expect( $first->json( 'data.token' ) )->toBeString();

    $this->postJson( '/api/ecommerce/v1/carts', [], [ 'Idempotency-Key' => $key ] )
        ->assertCreated()
        ->assertHeader( 'Idempotent-Replay', 'true' )
        ->assertJsonMissingPath( 'data.token' );
} );

it( 'writes timestamps as RFC 3339 UTC in REST, GraphQL, and webhooks (F15)', function (): void {
    require_once __DIR__ . '/../GraphQL/GraphQLTestHelpers.php';

    $pattern = '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/';
    $product = ArtisanPackUI\Ecommerce\Models\Product::factory()->create();

    expect( $this->getJson( "/api/ecommerce/v1/products/{$product->id}" )->json( 'data.created_at' ) )->toMatch( $pattern )
        ->and( gql( $this, '{ products { nodes { created_at } } }' )->json( 'data.products.nodes.0.created_at' ) )->toMatch( $pattern );

    $payload = app( ArtisanPackUI\Ecommerce\Webhooks\WebhookPayloadFactory::class )->serialize( [ 'at' => Illuminate\Support\Carbon::parse( '2026-03-02 10:04:05', 'America/New_York' ) ] );

    expect( $payload['at'] )->toBe( '2026-03-02T15:04:05Z' );
} );
