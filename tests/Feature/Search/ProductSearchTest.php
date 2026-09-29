<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\Engines\CollectionEngine;
use Laravel\Scout\Engines\DatabaseEngine;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Product::factory()->create( [ 'name' => 'Organic Cotton Shirt', 'sku' => 'SHIRT-01', 'slug' => 'organic-cotton-shirt' ] );
    Product::factory()->create( [ 'name' => 'Wool Scarf', 'sku' => 'SCARF-01', 'slug' => 'wool-scarf', 'description' => 'Pairs well with any shirt.' ] );
    Product::factory()->create( [ 'name' => 'Draft Shirt', 'sku' => 'SHIRT-99', 'slug' => 'draft-shirt', 'status' => 'draft' ] );
} );

it( 'searches products with the database driver by default', function (): void {
    expect( ( new Product() )->searchableUsing() )->toBeInstanceOf( DatabaseEngine::class );

    expect( Product::search( 'shirt' )->get()->pluck( 'slug' )->all() )
        ->toEqualCanonicalizing( [ 'organic-cotton-shirt', 'wool-scarf', 'draft-shirt' ] );

    expect( Product::search( 'SCARF-01' )->get()->pluck( 'slug' )->all() )->toBe( [ 'wool-scarf' ] );

    expect( Product::search( 'shirt' )->query( fn ( $query ) => $query->storefrontVisible() )->get()->pluck( 'slug' )->all() )
        ->toEqualCanonicalizing( [ 'organic-cotton-shirt', 'wool-scarf' ] );
} );

it( 'runs the searchable-data filter and keeps only real columns for the database driver', function (): void {
    addFilter( 'ap.ecommerce.product.searchableData', function ( array $data, Product $product ): array {
        $data['brand'] = 'Acme';

        return $data;
    } );

    $product = Product::query()->where( 'slug', 'wool-scarf' )->sole();

    expect( $product->toSearchableArray() )->not->toHaveKey( 'brand' )
        ->and( array_keys( $product->toSearchableArray() ) )->toBe( Product::DATABASE_SEARCH_COLUMNS );

    config()->set( 'artisanpack.ecommerce.search.driver', 'collection' );

    expect( $product->searchableUsing() )->toBeInstanceOf( CollectionEngine::class )
        ->and( $product->toSearchableArray() )->toMatchArray( [ 'brand' => 'Acme', 'status' => 'active', 'type' => $product->type ] );

    // Every product carries the filtered field, but only active ones are
    // indexed on dedicated engines — the draft is left out.
    expect( Product::search( 'Acme' )->get() )->toHaveCount( 2 );
} );

it( 'falls back to scout.driver when the ecommerce driver is blank', function (): void {
    config()->set( 'artisanpack.ecommerce.search.driver', null );
    config()->set( 'scout.driver', 'collection' );

    expect( ( new Product() )->searchableUsing() )->toBeInstanceOf( CollectionEngine::class );
} );

it( 'namespaces the index and honours the scout feature flag', function (): void {
    config()->set( 'scout.prefix', 'shop_' );

    $product = new Product( [ 'status' => 'active' ] );

    expect( $product->searchableAs() )->toBe( 'shop_ecommerce_products' )
        ->and( $product->shouldBeSearchable() )->toBeTrue()
        ->and( ( new Product( [ 'status' => 'draft' ] ) )->shouldBeSearchable() )->toBeFalse();

    config()->set( 'artisanpack.ecommerce.features.scout', false );

    expect( $product->shouldBeSearchable() )->toBeFalse();
} );

it( 'exposes storefront search over REST', function (): void {
    $this->getJson( '/api/ecommerce/v1/search?q=shirt' )
        ->assertOk()
        ->assertJsonCount( 2, 'data' )
        ->assertJsonPath( 'data.0.type', 'product' )
        ->assertJsonPath( 'meta.total', 2 );

    $this->getJson( '/api/ecommerce/v1/search?q=shirt&per_page=1' )->assertOk()->assertJsonCount( 1, 'data' );

    $this->getJson( '/api/ecommerce/v1/search' )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'q' );
} );
