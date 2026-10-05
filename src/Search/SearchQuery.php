<?php

/**
 * SearchQuery.
 *
 * What a storefront searches for (#176): the term, catalog filters (the
 * `GET products` filter keys, plus `attributes` as key → values), sort,
 * page, page size, and currency.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Search;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class SearchQuery
{
    /**
     * @since 1.0.0
     *
     * @param  string                $term      Search term.
     * @param  array<string, mixed>  $filters   Catalog filters.
     * @param  string|null           $sort      A {@see \ArtisanPackUI\Ecommerce\Catalog\CatalogQuery::SORTS} value; null for relevance.
     * @param  int                   $page      Page (1-based).
     * @param  int                   $perPage   Page size.
     * @param  string|null           $currency  Currency for price filters, sorting, and facets.
     * @param  array<int|string, mixed>  $with  Relations to eager-load on the results.
     */
    public function __construct(
        public readonly string $term,
        public readonly array $filters = [],
        public readonly ?string $sort = null,
        public readonly int $page = 1,
        public readonly int $perPage = 25,
        public readonly ?string $currency = null,
        public readonly array $with = [],
    ) {
    }
}
