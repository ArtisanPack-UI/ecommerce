<?php

/**
 * SearchProvider.
 *
 * Product search for storefronts (#176): a term plus catalog filters in, a
 * page of products with facet counts and suggestions out. The core provider
 * uses Scout and {@see \ArtisanPackUI\Ecommerce\Catalog\CatalogQuery};
 * search satellites (Meilisearch, Typesense, Algolia) register their own in
 * {@see \ArtisanPackUI\Ecommerce\Registries\SearchProviderRegistry} and
 * storefronts never need to know which is active.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Search\SearchQuery;
use ArtisanPackUI\Ecommerce\Search\SearchResult;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface SearchProvider
{
    /**
     * Registry key.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string;

    /**
     * Human label.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string;

    /**
     * Runs `$query`. Results hold only storefront-visible products; filters
     * and facets use {@see \ArtisanPackUI\Ecommerce\Catalog\CatalogQuery}'s
     * keys so storefronts work with any provider.
     *
     * @since 1.0.0
     *
     * @param  SearchQuery  $query  Query.
     *
     * @return SearchResult
     */
    public function search( SearchQuery $query ): SearchResult;
}
