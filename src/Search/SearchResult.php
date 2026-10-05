<?php

/**
 * SearchResult.
 *
 * A page of search results (#176): the products, the total match count,
 * facet counts over every match (not just the page), and suggestions such
 * as spelling corrections.
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

use ArtisanPackUI\Ecommerce\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class SearchResult
{
    /**
     * @since 1.0.0
     *
     * @param  Collection<int, Product>  $items        Products on this page.
     * @param  int                       $total        All matches.
     * @param  array<string, mixed>      $facets       Facet counts.
     * @param  array<int, string>        $suggestions  Suggested terms.
     * @param  int                       $page         Page.
     * @param  int                       $perPage      Page size.
     */
    public function __construct(
        public readonly Collection $items,
        public readonly int $total,
        public readonly array $facets = [],
        public readonly array $suggestions = [],
        public readonly int $page = 1,
        public readonly int $perPage = 25,
    ) {
    }

    /**
     * The page as a paginator, for resource responses.
     *
     * @since 1.0.0
     *
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator( $this->items, $this->total, max( 1, $this->perPage ), max( 1, $this->page ), [ 'path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page' ] );
    }
}
