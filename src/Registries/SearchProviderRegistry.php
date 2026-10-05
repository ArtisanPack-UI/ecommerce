<?php

/**
 * SearchProviderRegistry.
 *
 * Search providers (#176). The active one is `search.provider`.
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

use ArtisanPackUI\Ecommerce\Contracts\SearchProvider;
use ArtisanPackUI\Ecommerce\Search\DatabaseSearchProvider;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @extends AbstractContractRegistry<SearchProvider>
 *
 * @method SearchProvider get( string $key )
 */
class SearchProviderRegistry extends AbstractContractRegistry
{
    /**
     * The provider `search.provider` names, falling back to the core
     * provider when it isn't registered.
     *
     * @since 1.0.0
     *
     * @return SearchProvider
     */
    public function active(): SearchProvider
    {
        $key = (string) config( 'artisanpack.ecommerce.search.provider', DatabaseSearchProvider::KEY );

        return $this->get( $this->has( $key ) ? $key : DatabaseSearchProvider::KEY );
    }

    /**
     * @since 1.0.0
     *
     * @return class-string<SearchProvider>
     */
    protected function contract(): string
    {
        return SearchProvider::class;
    }
}
