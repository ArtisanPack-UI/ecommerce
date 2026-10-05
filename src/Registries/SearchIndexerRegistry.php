<?php

/**
 * SearchIndexerRegistry.
 *
 * Custom search indexers (engine spec §4.15, #176).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Registries;

use ArtisanPackUI\Ecommerce\Contracts\SearchIndexer;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @extends AbstractContractRegistry<SearchIndexer>
 *
 * @method SearchIndexer get( string $key )
 */
class SearchIndexerRegistry extends AbstractContractRegistry
{
    /**
     * @since 1.0.0
     *
     * @return class-string<SearchIndexer>
     */
    protected function contract(): string
    {
        return SearchIndexer::class;
    }
}
