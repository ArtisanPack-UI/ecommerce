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
