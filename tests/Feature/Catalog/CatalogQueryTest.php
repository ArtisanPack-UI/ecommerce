<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Catalog\CatalogQuery;
use ArtisanPackUI\Ecommerce\Catalog\CategoryTree;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductAttribute;
use ArtisanPackUI\Ecommerce\Models\ProductAttributeValue;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses( RefreshDatabase::class );

/**
 * A visible product priced at `$usd` (and optionally a compare-at price).
 *
 * @param  array<string, mixed>  $attributes
 */
function cqProduct( string $name, int $usd, array $attributes = [], ?int $compareAt = null ): Product
{
    $product = Product::factory()->create( [ 'name' => $name ] + $attributes );
    ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => $usd, 'compare_at_amount' => $compareAt ] );

    return $product;
}

function cqNames( CatalogQuery $query ): array
{
    return $query->builder()->pluck( 'name' )->all();
}

function cq(): CatalogQuery
{
    return app( CatalogQuery::class );
}

beforeEach( function (): void {
    $this->mug    = cqProduct( 'Mug', 1_500, [ 'position' => 2 ] );
    $this->shirt  = cqProduct( 'Shirt', 2_500, [ 'position' => 1, 'avg_rating' => 4.5, 'is_featured' => true ], 3_000 );
    $this->poster = cqProduct( 'Poster', 800, [ 'position' => 3, 'avg_rating' => 3.0 ] );
    Product::factory()->draft()->create( [ 'name' => 'Draft' ] );
} );

describe( 'filters', function (): void {
    it( 'only ever returns visible products, in manual position by default', function (): void {
        expect( cqNames( cq() ) )->toBe( [ 'Shirt', 'Mug', 'Poster' ] );
    } );

    it( 'filters by category with its descendants, or that category only', function (): void {
        $root  = ProductCategory::factory()->create( [ 'slug' => 'home' ] );
        $child = ProductCategory::factory()->create( [ 'parent_id' => $root->id, 'slug' => 'kitchen' ] );
        $this->mug->categories()->attach( $child->id );
        $this->poster->categories()->attach( $root->id );

        expect( cqNames( cq()->inCategory( 'home' ) ) )->toEqualCanonicalizing( [ 'Mug', 'Poster' ] )
            ->and( cqNames( cq()->inCategory( 'home', false ) ) )->toBe( [ 'Poster' ] )
            ->and( cqNames( cq()->inCategory( $child->id ) ) )->toBe( [ 'Mug' ] )
            ->and( cqNames( cq()->inCategory( 'nope' ) ) )->toBe( [] );
    } );

    it( 'filters by tag, price range, attribute, rating, featured, ids, and sale', function (): void {
        $tag = ProductTag::factory()->create( [ 'slug' => 'gifts' ] );
        $this->mug->tags()->attach( $tag->id );
        $color = ProductAttribute::factory()->create( [ 'product_id' => $this->shirt->id, 'key' => 'color' ] );
        ProductAttributeValue::factory()->create( [ 'product_attribute_id' => $color->id, 'value' => 'red' ] );

        expect( cqNames( cq()->withTag( 'gifts' ) ) )->toBe( [ 'Mug' ] )
            ->and( cqNames( cq()->priceBetween( 1_000, 2_000 ) ) )->toBe( [ 'Mug' ] )
            ->and( cqNames( cq()->priceBetween( null, 1_000 ) ) )->toBe( [ 'Poster' ] )
            ->and( cqNames( cq()->withAttribute( 'color', [ 'red', 'blue' ] ) ) )->toBe( [ 'Shirt' ] )
            ->and( cqNames( cq()->withAttribute( 'color', [ 'green' ] ) ) )->toBe( [] )
            ->and( cqNames( cq()->minRating( 4 ) ) )->toBe( [ 'Shirt' ] )
            ->and( cqNames( cq()->featured() ) )->toBe( [ 'Shirt' ] )
            ->and( cqNames( cq()->ids( [ $this->poster->id, $this->mug->id ] ) ) )->toBe( [ 'Mug', 'Poster' ] )
            ->and( cqNames( cq()->onSale() ) )->toBe( [ 'Shirt' ] );
    } );

    it( 'treats untracked, backorderable, and stocked products as in stock', function (): void {
        InventoryItem::factory()->create( [ 'stockable_type' => $this->mug->getMorphClass(), 'stockable_id' => $this->mug->id, 'quantity_on_hand' => 0 ] );
        $variant = ProductVariant::factory()->create( [ 'product_id' => $this->shirt->id ] );
        InventoryItem::factory()->create( [ 'stockable_type' => $variant->getMorphClass(), 'stockable_id' => $variant->id, 'quantity_on_hand' => 2 ] );

        expect( cqNames( cq()->inStock() ) )->toEqualCanonicalizing( [ 'Shirt', 'Poster' ] );
    } );

    it( 'searches, ranking names that start with the term first', function (): void {
        cqProduct( 'Coffee mug holder', 900 );

        expect( cqNames( cq()->search( 'mug' ) ) )->toBe( [ 'Mug', 'Coffee mug holder' ] );
    } );

    it( 'prices in another currency, converting base prices that have no row in it', function (): void {
        config()->set( 'artisanpack.ecommerce.currency.rates', [ 'USD' => [ 'EUR' => 50_000_000 ] ] );
        ProductPrice::factory()->forPriceable( $this->shirt )->create( [ 'currency' => 'EUR', 'price_amount' => 100 ] );

        expect( cqNames( cq()->currency( 'EUR' )->priceBetween( null, 500 ) ) )->toBe( [ 'Shirt', 'Poster' ] );
    } );
} );

describe( 'sorts', function (): void {
    it( 'sorts by price, rating, name, newest, and popularity', function (): void {
        $order = Order::factory()->create( [ 'payment_status' => 'paid' ] );
        OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => $this->poster->id, 'quantity' => 5 ] );
        OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => $this->mug->id, 'quantity' => 2 ] );
        Product::query()->whereKey( $this->poster->id )->update( [ 'published_at' => Carbon::now()->subMinute() ] );

        expect( cqNames( cq()->sort( 'price' ) ) )->toBe( [ 'Poster', 'Mug', 'Shirt' ] )
            ->and( cqNames( cq()->sort( '-price' ) ) )->toBe( [ 'Shirt', 'Mug', 'Poster' ] )
            ->and( cqNames( cq()->sort( 'rating' ) ) )->toBe( [ 'Shirt', 'Poster', 'Mug' ] )
            ->and( cqNames( cq()->sort( 'name' ) ) )->toBe( [ 'Mug', 'Poster', 'Shirt' ] )
            ->and( cqNames( cq()->sort( 'popularity' ) ) )->toBe( [ 'Poster', 'Mug', 'Shirt' ] );
    } );
} );

it( 'aggregates facets for the current filter set', function (): void {
    $category = ProductCategory::factory()->create();
    $this->mug->categories()->attach( $category->id );
    $this->shirt->categories()->attach( $category->id );
    $size = ProductAttribute::factory()->create( [ 'product_id' => $this->mug->id, 'key' => 'size' ] );
    ProductAttributeValue::factory()->create( [ 'product_attribute_id' => $size->id, 'value' => 'l', 'label' => 'Large' ] );
    InventoryItem::factory()->create( [ 'stockable_type' => $this->shirt->getMorphClass(), 'stockable_id' => $this->shirt->id, 'quantity_on_hand' => 0 ] );

    $facets = cq()->inCategory( $category->id )->facets();

    expect( $facets['price'] )->toBe( [ 'min' => 1_500, 'max' => 2_500, 'currency' => 'USD' ] )
        ->and( $facets['attributes']['size'] )->toBe( [ [ 'value' => 'l', 'label' => 'Large', 'count' => 1 ] ] )
        ->and( $facets['categories'] )->toBe( [ $category->id => 2 ] )
        ->and( $facets['stock'] )->toBe( [ 'in_stock' => 1, 'total' => 2 ] );
} );

it( 'runs every listing through product.listQuery', function (): void {
    addFilter( 'ap.ecommerce.product.listQuery', fn ( Builder $query, array $params ) => $query->where( 'name', '!=', 'Mug' ) );

    expect( cqNames( cq() ) )->toBe( [ 'Shirt', 'Poster' ] );
} );

it( 'keeps the category tree cached until a category changes', function (): void {
    $root = ProductCategory::factory()->create( [ 'name' => 'Home' ] );
    ProductCategory::factory()->create( [ 'parent_id' => $root->id, 'name' => 'Kitchen' ] );

    expect( app( CategoryTree::class )->tree()[0]['children'][0]['name'] )->toBe( 'Kitchen' );

    $root->update( [ 'name' => 'House' ] );

    expect( app( CategoryTree::class )->tree()[0]['name'] )->toBe( 'House' )
        ->and( app( CatalogQuery::class )->productBySlug( $this->mug->slug )?->id )->toBe( $this->mug->id );
} );

describe( 'REST (F3)', function (): void {
    it( 'serves the tree, a category by slug, its products, and tags', function (): void {
        $root  = ProductCategory::factory()->create( [ 'slug' => 'home', 'name' => 'Home' ] );
        $child = ProductCategory::factory()->create( [ 'parent_id' => $root->id, 'slug' => 'kitchen' ] );
        $this->mug->categories()->attach( $child->id );
        $tag = ProductTag::factory()->create( [ 'name' => 'Gifts' ] );
        $this->mug->tags()->attach( $tag->id );

        $this->getJson( '/api/ecommerce/v1/categories' )->assertOk()->assertJsonPath( 'data.0.children.0.slug', 'kitchen' );
        $this->getJson( '/api/ecommerce/v1/categories/home?include=children' )->assertOk()->assertJsonPath( 'data.children.0.slug', 'kitchen' );
        $this->getJson( '/api/ecommerce/v1/categories/home/products' )->assertOk()->assertJsonPath( 'data.0.name', 'Mug' );
        $this->getJson( '/api/ecommerce/v1/categories/missing' )->assertNotFound();
        $this->getJson( '/api/ecommerce/v1/tags' )->assertOk()->assertJsonPath( 'data.0.products_count', 1 );
    } );

    it( 'lists products with catalog filters, includes, computed sorts, and facets', function (): void {
        $category = ProductCategory::factory()->create( [ 'slug' => 'gear' ] );
        $this->mug->categories()->attach( $category->id );

        $this->getJson( '/api/ecommerce/v1/products?include=images,categories,tags' )->assertOk()->assertJsonStructure( [ 'data' => [ [ 'images', 'categories', 'tags' ] ] ] );
        $this->getJson( '/api/ecommerce/v1/products?filter[category]=gear' )->assertOk()->assertJsonCount( 1, 'data' );
        $this->getJson( '/api/ecommerce/v1/products?filter[price_max]=1000' )->assertOk()->assertJsonPath( 'data.0.name', 'Poster' );

        $this->getJson( '/api/ecommerce/v1/products?sort=-price&per_page=2' )
            ->assertOk()
            ->assertJsonPath( 'data.*.name', [ 'Shirt', 'Mug' ] )
            ->assertJsonPath( 'meta.last_page', 2 );

        $this->getJson( '/api/ecommerce/v1/products?facets=1' )->assertOk()->assertJsonPath( 'meta.facets.price.max', 2_500 );
        $this->getJson( '/api/ecommerce/v1/products?filter[bogus]=1' )->assertStatus( 400 );
    } );

    it( 'honours include on search', function (): void {
        $this->getJson( '/api/ecommerce/v1/search?q=mug&include=prices' )->assertOk()->assertJsonPath( 'data.0.prices.0.price.amount', 1_500 );
    } );
} );
