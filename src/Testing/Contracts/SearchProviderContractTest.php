<?php

/**
 * SearchProvider contract test.
 *
 * Search satellites that ship a {@see SearchProvider} extend this suite,
 * implement {@see self::provider()}, and override
 * {@see self::index()} when their engine needs products pushed to it
 * before they can be found (#176).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\SearchProvider;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use ArtisanPackUI\Ecommerce\Search\SearchQuery;
use ArtisanPackUI\Ecommerce\Search\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

/**
 * Contract test for {@see SearchProvider} implementations.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class SearchProviderContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_key_and_label_are_well_formed(): void
    {
        $this->assertMatchesRegularExpression( '/^[a-z0-9]+(?:[-_.][a-z0-9]+)*$/', $this->provider()->key() );
        $this->assertNotSame( '', trim( $this->provider()->label() ) );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_finds_visible_products_by_term_and_never_hidden_ones(): void
    {
        $shirt = $this->product( 'Zephyrine linen shirt' );
        $draft = $this->product( 'Zephyrine linen draft', [ 'status' => 'draft' ] );
        $this->product( 'Plain mug' );
        $this->index();

        $result = $this->provider()->search( new SearchQuery( 'Zephyrine' ) );

        $this->assertInstanceOf( SearchResult::class, $result );
        $this->assertContainsOnlyInstancesOf( Product::class, $result->items->all() );
        $this->assertContains( $shirt->id, $result->items->pluck( 'id' )->all() );
        $this->assertNotContains( $draft->id, $result->items->pluck( 'id' )->all(), 'Products shoppers can\'t see must never be returned.' );
        $this->assertSame( 1, $result->total );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_pages_through_matches_with_the_total(): void
    {
        foreach ( [ 'Quillfeather pen A', 'Quillfeather pen B', 'Quillfeather pen C' ] as $name ) {
            $this->product( $name );
        }

        $this->index();

        $first  = $this->provider()->search( new SearchQuery( 'Quillfeather', page: 1, perPage: 2 ) );
        $second = $this->provider()->search( new SearchQuery( 'Quillfeather', page: 2, perPage: 2 ) );

        $this->assertSame( 3, $first->total );
        $this->assertCount( 2, $first->items );
        $this->assertCount( 1, $second->items );
        $this->assertSame( [], array_intersect( $first->items->pluck( 'id' )->all(), $second->items->pluck( 'id' )->all() ) );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_applies_catalog_filters_and_reports_facets_and_suggestions(): void
    {
        $outdoor = ProductCategory::factory()->create( [ 'slug' => 'outdoor' ] );
        $tent    = $this->product( 'Marmotfield tent' );
        $tent->categories()->attach( $outdoor->id );
        $this->product( 'Marmotfield mug' );
        $this->index();

        $result = $this->provider()->search( new SearchQuery( 'Marmotfield', [ 'category' => 'outdoor' ] ) );

        $this->assertSame( [ $tent->id ], $result->items->pluck( 'id' )->all() );
        $this->assertIsArray( $result->facets );
        $this->assertContainsOnly( 'string', $result->suggestions );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_or_unmatched_term_finds_nothing_without_failing(): void
    {
        $this->product( 'Ordinary candle' );
        $this->index();

        $this->assertSame( 0, $this->provider()->search( new SearchQuery( '' ) )->total );
        $this->assertSame( 0, $this->provider()->search( new SearchQuery( 'xqzvbnmlkj' ) )->total );
    }

    /**
     * Provides the concrete {@see SearchProvider} under test.
     *
     * @since 1.0.0
     *
     * @return SearchProvider
     */
    abstract protected function provider(): SearchProvider;

    /**
     * Pushes the products created so far to the provider's engine. The
     * core provider searches the database directly, so the default does
     * nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function index(): void
    {
    }

    /**
     * A product priced in USD.
     *
     * @since 1.0.0
     *
     * @param  string                $name        Name.
     * @param  array<string, mixed>  $attributes  Extra columns.
     *
     * @return Product
     */
    protected function product( string $name, array $attributes = [] ): Product
    {
        $product = Product::factory()->create( [ 'name' => $name ] + $attributes );
        ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => 1_000 ] );

        return $product;
    }

    /**
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  Test application.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders( $app ): array
    {
        return [ EcommerceServiceProvider::class ];
    }

    /**
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  Test application.
     */
    protected function defineEnvironment( $app ): void
    {
        $app['config']->set( 'app.key', 'base64:' . base64_encode( random_bytes( 32 ) ) );
        $app['config']->set( 'database.default', 'testbench' );
        $app['config']->set( 'database.connections.testbench', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ] );
    }
}
