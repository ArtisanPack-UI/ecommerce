<?php

/**
 * SyncSearchIndexers.
 *
 * Keeps every registered {@see SearchIndexer} in step with the catalog
 * (engine spec §4.15, #176): a saved product that shoppers can see is
 * indexed, one they can't is removed, and a deleted one is removed. Laravel
 * Scout keeps its own index in step by itself.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Listeners;

use ArtisanPackUI\Ecommerce\Contracts\SearchIndexer;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Registries\SearchIndexerRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SyncSearchIndexers
{
    /**
     * Hooks the product lifecycle.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function register(): void
    {
        addAction( 'ap.ecommerce.product.saved', [ self::class, 'saved' ] );
        addAction( 'ap.ecommerce.product.deleted', [ self::class, 'deleted' ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  Product  $product  Saved product.
     *
     * @return void
     */
    public static function saved( Product $product ): void
    {
        $visible = Product::query()->storefrontVisible()->whereKey( $product->getKey() )->exists();

        self::each( static fn ( SearchIndexer $indexer ) => $visible ? $indexer->indexMany( [ $product ] ) : $indexer->delete( $product ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  Product  $product  Deleted product.
     *
     * @return void
     */
    public static function deleted( Product $product ): void
    {
        self::each( static fn ( SearchIndexer $indexer ) => $indexer->delete( $product ) );
    }

    /**
     * Runs `$callback` for each indexer; one failing doesn't stop the rest
     * or the save.
     *
     * @since 1.0.0
     *
     * @param  callable(SearchIndexer): mixed  $callback  Work.
     *
     * @return void
     */
    protected static function each( callable $callback ): void
    {
        $registry = app( SearchIndexerRegistry::class );

        foreach ( $registry->keys() as $key ) {
            try {
                $callback( $registry->get( $key ) );
            } catch ( Throwable $e ) {
                Log::channel( 'ecommerce' )->error( 'ecommerce.search.indexer_failed', [ 'indexer' => $key, 'error' => $e->getMessage() ] );
            }
        }
    }
}
