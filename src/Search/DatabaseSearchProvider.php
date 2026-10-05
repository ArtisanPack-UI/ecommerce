<?php

/**
 * DatabaseSearchProvider.
 *
 * The core {@see SearchProvider} (#176): the term goes to Laravel Scout
 * (the database driver unless `search.driver` names another engine) through
 * {@see CatalogQuery}, which then applies the catalog filters, the sort
 * (relevance by default), and the facet counts over every match. Works
 * with no search infrastructure; search satellites replace it.
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

use ArtisanPackUI\Ecommerce\Catalog\CatalogQuery;
use ArtisanPackUI\Ecommerce\Contracts\SearchProvider;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DatabaseSearchProvider implements SearchProvider
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'default';

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Store search' );
    }

    /**
     * @since 1.0.0
     *
     * @param  SearchQuery  $query  Query.
     *
     * @return SearchResult
     */
    public function search( SearchQuery $query ): SearchResult
    {
        $term = trim( $query->term );

        $catalog = app( CatalogQuery::class )->fromParameters(
            [ ...$query->filters, 'search' => '' === $term ? null : $term ],
            $query->sort,
            $query->currency,
        );

        // An empty term searches nothing rather than listing the catalog.
        if ( '' === $term ) {
            $catalog->ids( [] );
        }

        $paginator = $catalog->builder()->with( $query->with )->paginate( max( 1, $query->perPage ), [ '*' ], 'page', max( 1, $query->page ) );

        return new SearchResult(
            collect( $paginator->items() ),
            $paginator->total(),
            $catalog->facets(),
            [],
            $paginator->currentPage(),
            $paginator->perPage(),
        );
    }
}
