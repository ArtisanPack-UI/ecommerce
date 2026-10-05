<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\SearchIndexer;
use ArtisanPackUI\Ecommerce\Contracts\SearchProvider;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductAttribute;
use ArtisanPackUI\Ecommerce\Models\ProductAttributeValue;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use ArtisanPackUI\Ecommerce\Registries\SearchIndexerRegistry;
use ArtisanPackUI\Ecommerce\Registries\SearchProviderRegistry;
use ArtisanPackUI\Ecommerce\Search\SearchQuery;
use ArtisanPackUI\Ecommerce\Search\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

/**
 * A visible product priced at `$usd`.
 */
function searchableProduct( string $name, int $usd = 1_000 ): Product
{
    $product = Product::factory()->create( [ 'name' => $name ] );
    ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => $usd ] );

    return $product;
}

it( 'searches through the active provider with filters, sort, facets, and suggestions over REST', function (): void {
    $linen = ProductCategory::factory()->create( [ 'slug' => 'linen' ] );
    $cheap = searchableProduct( 'Linen shirt basic', 1_500 );
    $dear  = searchableProduct( 'Linen shirt deluxe', 4_000 );
    $cheap->categories()->attach( $linen->id );
    $dear->categories()->attach( $linen->id );
    searchableProduct( 'Linen towel', 900 );

    $this->getJson( '/api/ecommerce/v1/search?q=linen+shirt&filter[category]=linen&sort=-price&currency=usd&per_page=1' )
        ->assertOk()
        ->assertJsonPath( 'data.0.id', $dear->id )
        ->assertJsonPath( 'meta.total', 2 )
        ->assertJsonPath( 'meta.facets.price.min', 1_500 )
        ->assertJsonPath( 'meta.facets.price.max', 4_000 )
        ->assertJsonPath( 'meta.suggestions', [] );

    $this->getJson( '/api/ecommerce/v1/search?q=linen&filter[price_max]=1000' )
        ->assertOk()
        ->assertJsonPath( 'meta.total', 1 );
} );

it( 'uses a satellite\'s provider when search.provider names it', function (): void {
    $product = searchableProduct( 'Anything' );

    app( SearchProviderRegistry::class )->register( 'fake', new class( $product ) implements SearchProvider {
        public function __construct( private readonly Product $product )
        {
        }

        public function key(): string
        {
            return 'fake';
        }

        public function label(): string
        {
            return 'Fake';
        }

        public function search( SearchQuery $query ): SearchResult
        {
            return new SearchResult( collect( [ $this->product ] ), 1, [ 'brand' => [ 'acme' => 1 ] ], [ 'anything' ], $query->page, $query->perPage );
        }
    } );

    config()->set( 'artisanpack.ecommerce.search.provider', 'fake' );

    $this->getJson( '/api/ecommerce/v1/search?q=anythng' )
        ->assertOk()
        ->assertJsonPath( 'data.0.id', $product->id )
        ->assertJsonPath( 'meta.facets.brand.acme', 1 )
        ->assertJsonPath( 'meta.suggestions', [ 'anything' ] );

    // An unknown provider falls back to the core one.
    config()->set( 'artisanpack.ecommerce.search.provider', 'missing' );

    expect( app( SearchProviderRegistry::class )->active()->key() )->toBe( 'default' );
} );

it( 'keeps registered search indexers in step with the catalog', function (): void {
    $indexer = new class implements SearchIndexer {
        /** @var array<int, string> */
        public array $calls = [];

        public function key(): string
        {
            return 'memory';
        }

        public function indexMany( iterable $products ): void
        {
            foreach ( $products as $product ) {
                $this->calls[] = 'index:' . $product->id;
            }
        }

        public function delete( Product $product ): void
        {
            $this->calls[] = 'delete:' . $product->id;
        }

        public function flush(): void
        {
            $this->calls[] = 'flush';
        }
    };

    app( SearchIndexerRegistry::class )->register( 'memory', $indexer );

    $product = searchableProduct( 'Lamp' );
    $product->update( [ 'status' => 'draft' ] );
    $product->delete();

    // Created (indexed), hidden as a draft (removed), deleted (removed).
    expect( $indexer->calls )->toBe( [ 'index:' . $product->id, 'delete:' . $product->id, 'delete:' . $product->id ] );
} );

it( 'feeds dedicated engines the facetable fields', function (): void {
    config()->set( 'artisanpack.ecommerce.search.driver', 'collection' );

    $product   = searchableProduct( 'Shirt', 2_500 );
    $category  = ProductCategory::factory()->create( [ 'slug' => 'tops' ] );
    $tag       = ProductTag::factory()->create( [ 'slug' => 'summer' ] );
    $attribute = ProductAttribute::factory()->for( $product )->create( [ 'key' => 'size' ] );
    ProductAttributeValue::factory()->for( $attribute, 'attribute' )->create( [ 'value' => 'm' ] );
    $product->categories()->attach( $category->id );
    $product->tags()->attach( $tag->id );

    $document = $product->fresh()->toSearchableArray();

    expect( $document )->toMatchArray( [
        'category_ids'   => [ $category->id ],
        'category_slugs' => [ 'tops' ],
        'tag_slugs'      => [ 'summer' ],
        'attributes'     => [ 'size' => [ 'm' ] ],
        'prices'         => [ 'USD' => 2_500 ],
        'in_stock'       => true,
    ] );

    config()->set( 'artisanpack.ecommerce.search.driver', 'database' );

    expect( $product->fresh()->toSearchableArray() )->not->toHaveKey( 'prices' );
} );
