<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\ProductWriteException;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use ArtisanPackUI\Ecommerce\Services\ProductCategoryService;
use ArtisanPackUI\Ecommerce\Services\ProductTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'creates categories with unique slugs and appends them to their parent', function (): void {
    $service = app( ProductCategoryService::class );
    $prints  = $service->create( [ 'name' => 'Prints' ] );
    $a3      = $service->create( [ 'name' => 'A3', 'parent_id' => $prints->id ] );
    $a4      = $service->create( [ 'name' => 'A4', 'parent_id' => $prints->id ] );
    $again   = $service->create( [ 'name' => 'Prints' ] );

    expect( $again->slug )->toBe( 'prints-2' )
        ->and( [ $a3->position, $a4->position ] )->toBe( [ 1, 2 ] )
        ->and( $prints->children->pluck( 'id' )->all() )->toBe( [ $a3->id, $a4->id ] );
} );

it( 'refuses to move a category under itself or a descendant', function (): void {
    $service = app( ProductCategoryService::class );
    $root    = $service->create( [ 'name' => 'Root' ] );
    $child   = $service->create( [ 'name' => 'Child', 'parent_id' => $root->id ] );

    expect( fn () => $service->update( $root, [ 'parent_id' => $child->id ] ) )->toThrow( ProductWriteException::class )
        ->and( fn () => $service->update( $root, [ 'parent_id' => $root->id ] ) )->toThrow( ProductWriteException::class )
        ->and( fn () => $service->update( $root, [ 'slug' => $child->slug ] ) )->toThrow( ProductWriteException::class );
} );

it( 'moves children up and unlinks products when a category is deleted', function (): void {
    $service = app( ProductCategoryService::class );
    $root    = $service->create( [ 'name' => 'Root' ] );
    $middle  = $service->create( [ 'name' => 'Middle', 'parent_id' => $root->id ] );
    $leaf    = $service->create( [ 'name' => 'Leaf', 'parent_id' => $middle->id ] );
    $product = Product::factory()->create();
    $product->categories()->attach( $middle->id );

    $service->delete( $middle );

    expect( $leaf->refresh()->parent_id )->toBe( $root->id )
        ->and( $product->categories()->count() )->toBe( 0 );
} );

it( 'reorders categories within a parent', function (): void {
    $service = app( ProductCategoryService::class );
    $a       = $service->create( [ 'name' => 'A' ] );
    $b       = $service->create( [ 'name' => 'B' ] );

    expect( $service->reorder( null, [ $b->id, $a->id ] )->pluck( 'id' )->all() )->toBe( [ $b->id, $a->id ] );
} );

it( 'creates, renames, and merges tags', function (): void {
    $service = app( ProductTagService::class );
    $sale    = $service->create( [ 'name' => 'Sale' ] );
    $deals   = $service->findOrCreate( 'Deals' );
    $product = Product::factory()->create();
    $product->tags()->attach( [ $sale->id, $deals->id ] );
    $second = Product::factory()->create();
    $second->tags()->attach( $deals->id );

    expect( $service->findOrCreate( 'sale' )->id )->toBe( $sale->id );

    $service->update( $sale, [ 'name' => 'On sale' ] );
    $service->merge( $deals, $sale );

    expect( ProductTag::query()->count() )->toBe( 1 )
        ->and( $sale->refresh()->name )->toBe( 'On sale' )
        ->and( $sale->products()->count() )->toBe( 2 )
        ->and( fn () => $service->merge( $sale, $sale ) )->toThrow( ProductWriteException::class )
        ->and( fn () => $service->create( [ 'name' => 'On sale', 'slug' => 'sale' ] ) )->toThrow( ProductWriteException::class );
} );

it( 'links products to categories and tags through the product service', function (): void {
    $products = app( ArtisanPackUI\Ecommerce\Services\ProductService::class );
    $product  = Product::factory()->create();
    $a        = ProductCategory::factory()->create();
    $b        = ProductCategory::factory()->create();

    $products->setCategories( $product, [ $a->id ] );
    $products->setCategories( $product, [ $b->id ], 'attach' );
    $products->setCategories( $product, [ $a->id ], 'detach' );

    expect( $product->categories()->pluck( 'ecommerce_product_categories.id' )->all() )->toBe( [ $b->id ] )
        ->and( fn () => $products->setTags( $product, [ 999 ] ) )->toThrow( ProductWriteException::class );
} );

it( 'gives a new tag a unique slug when its name\'s slug is taken', function (): void {
    $service = app( ProductTagService::class );
    $service->create( [ 'name' => 'Red' ] );

    expect( $service->create( [ 'name' => 'Red!' ] )->slug )->toBe( 'red-2' );
} );
