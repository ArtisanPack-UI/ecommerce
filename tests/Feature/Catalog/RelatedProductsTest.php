<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Catalog\RelatedProducts;
use ArtisanPackUI\Ecommerce\Exceptions\ProductWriteException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\Models\ProductRelation;
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use ArtisanPackUI\Ecommerce\Services\ProductService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/../Api/ApiTestHelpers.php';

uses( RefreshDatabase::class );

function related(): RelatedProducts
{
    return app( RelatedProducts::class );
}

it( 'stores ordered hand-picked links and refuses self-links and duplicates', function (): void {
    [ $phone, $case, $charger ] = Product::factory()->count( 3 )->create()->all();

    app( ProductService::class )->syncProductRelations( $phone, ProductRelation::CROSS_SELL, [ $charger->id, $case->id ] );

    expect( ProductRelation::query()->where( 'product_id', $phone->id )->orderBy( 'position' )->pluck( 'related_product_id' )->all() )->toBe( [ $charger->id, $case->id ] );

    expect( fn () => app( ProductService::class )->syncProductRelations( $phone, ProductRelation::UPSELL, [ $phone->id ] ) )->toThrow( ProductWriteException::class )
        ->and( fn () => app( ProductService::class )->syncProductRelations( $phone, ProductRelation::UPSELL, [ $case->id, $case->id ] ) )->toThrow( ProductWriteException::class )
        ->and( fn () => app( ProductService::class )->syncProductRelations( $phone, 'sidegrade', [ $case->id ] ) )->toThrow( ProductWriteException::class );

    // Replacing a list leaves the other types alone.
    app( ProductService::class )->syncProductRelations( $phone, ProductRelation::UPSELL, [ $case->id ] );
    app( ProductService::class )->syncProductRelations( $phone, ProductRelation::UPSELL, [] );

    expect( ProductRelation::query()->where( 'product_id', $phone->id )->count() )->toBe( 2 );
} );

it( 'lists hand-picked upsells in order, visible only', function (): void {
    [ $phone, $pro, $max ] = Product::factory()->count( 3 )->create()->all();
    $hidden                = Product::factory()->create( [ 'status' => 'draft' ] );

    app( ProductService::class )->syncProductRelations( $phone, ProductRelation::UPSELL, [ $max->id, $hidden->id, $pro->id ] );

    expect( related()->for( $phone, ProductRelation::UPSELL )->modelKeys() )->toBe( [ $max->id, $pro->id ] );
} );

it( 'fills related products from shared categories, then tags', function (): void {
    $mugs   = ProductCategory::factory()->create();
    $gifts  = ProductCategory::factory()->create();
    $summer = ProductTag::factory()->create();

    $mug     = Product::factory()->create();
    $picked  = Product::factory()->create();
    $twoCats = Product::factory()->create();
    $oneCat  = Product::factory()->create();
    $tagged  = Product::factory()->create();
    Product::factory()->create();

    $mug->categories()->attach( [ $mugs->id, $gifts->id ] );
    $twoCats->categories()->attach( [ $mugs->id, $gifts->id ] );
    $oneCat->categories()->attach( $gifts->id );
    $mug->tags()->attach( $summer->id );
    $tagged->tags()->attach( $summer->id );

    app( ProductService::class )->syncProductRelations( $mug, ProductRelation::RELATED, [ $picked->id ] );

    expect( related()->for( $mug )->modelKeys() )->toBe( [ $picked->id, $twoCats->id, $oneCat->id, $tagged->id ] )
        ->and( related()->for( $mug, ProductRelation::RELATED, 2 )->modelKeys() )->toBe( [ $picked->id, $twoCats->id ] )
        // Upsells are never filled in.
        ->and( related()->for( $mug, ProductRelation::UPSELL ) )->toHaveCount( 0 );
} );

it( 'runs related lists through ap.ecommerce.product.related', function (): void {
    $mug = Product::factory()->create();
    addFilter( 'ap.ecommerce.product.related', fn ( Collection $products, Product $product, string $type ): Collection => new Collection( [ $product ] ) );

    expect( related()->for( $mug )->modelKeys() )->toBe( [ $mug->id ] );

    removeAllFilters( 'ap.ecommerce.product.related' );
} );

it( 'suggests the cart products\' cross-sells that aren\'t in the cart', function (): void {
    [ $phone, $case, $charger, $cable ] = Product::factory()->count( 4 )->create()->all();
    app( ProductService::class )->syncProductRelations( $phone, ProductRelation::CROSS_SELL, [ $case->id, $charger->id ] );
    app( ProductService::class )->syncProductRelations( $charger, ProductRelation::CROSS_SELL, [ $cable->id, $case->id ] );

    $cart = Cart::factory()->create();
    CartItem::factory()->create( [ 'cart_id' => $cart->id, 'product_id' => $phone->id ] );
    CartItem::factory()->create( [ 'cart_id' => $cart->id, 'product_id' => $charger->id ] );

    expect( related()->crossSellsForCart( $cart )->modelKeys() )->toBe( [ $case->id, $cable->id ] );

    $this->getJson( "/api/ecommerce/v1/carts/{$cart->token}/cross-sells" )
        ->assertOk()
        ->assertJsonPath( 'data.0.id', $case->id )
        ->assertJsonCount( 2, 'data' );
} );

it( 'reads related products over REST and writes them through the admin API', function (): void {
    [ $mug, $saucer, $spoon ] = Product::factory()->count( 3 )->create()->all();

    Gate::define( 'ecommerce.admin', fn (): bool => true );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->postJson( "/api/ecommerce/v1/admin/products/{$mug->id}/relations", [ 'type' => 'upsell', 'ids' => [ $spoon->id, $saucer->id ] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.0.id', $spoon->id )
        ->assertJsonPath( 'data.1.id', $saucer->id );

    $this->postJson( "/api/ecommerce/v1/admin/products/{$mug->id}/relations", [ 'type' => 'upsell', 'ids' => [ $mug->id ] ], idem() )->assertStatus( 422 );

    $this->getJson( "/api/ecommerce/v1/products/{$mug->id}/related?type=upsell" )
        ->assertOk()
        ->assertJsonPath( 'data.0.id', $spoon->id );

    $this->getJson( "/api/ecommerce/v1/products/{$mug->id}/related?type=nope" )->assertStatus( 400 );
} );

it( 'accepts relations in a product write', function (): void {
    $saucer = Product::factory()->create();

    $mug = app( ProductService::class )->create( [ 'name' => 'Mug', 'type' => 'simple', 'relations' => [ 'cross_sell' => [ $saucer->id ] ] ] );

    expect( related()->for( $mug, ProductRelation::CROSS_SELL )->modelKeys() )->toBe( [ $saucer->id ] );
} );
