<?php

/**
 * SearchController.
 *
 * `GET search?q=` — storefront product search (engine spec §9.1) through
 * the active {@see \ArtisanPackUI\Ecommerce\Contracts\SearchProvider}
 * (#176): the core one (Scout plus the catalog query) or a search
 * satellite's. Takes the `GET products` filters (`filter[...]`,
 * `attributes[...]`), sort, and currency, and answers storefront-visible
 * products with `meta.facets` and `meta.suggestions`. Results are
 * page-number paginated (`page`, `per_page`) because search engines don't
 * support cursors.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1;

use ArtisanPackUI\Ecommerce\Catalog\CatalogQuery;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductResource;
use ArtisanPackUI\Ecommerce\Http\Support\ListQuery;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\OpenApi\CatalogParameters;
use ArtisanPackUI\Ecommerce\Registries\SearchProviderRegistry;
use ArtisanPackUI\Ecommerce\Search\SearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SearchController extends ApiController
{
    /**
     * Deepest result position a search can page to.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_RESULTS = 10_000;

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation(
        summary: 'Search products',
        resource: ProductResource::class,
        includes: ProductController::OPENAPI_INCLUDES,
        query: CatalogParameters::SEARCH,
    )]
    public function index( Request $request ): JsonResponse
    {
        $raw  = $request->query( 'q' );
        $term = is_string( $raw ) ? trim( $raw ) : '';

        if ( '' === $term || mb_strlen( $term ) > 200 ) {
            return Problem::make( 422, 'validation-failed', __( 'Validation failed' ), __( 'The q parameter is required and must be at most 200 characters.' ), $request, [
                [ 'field' => 'q', 'code' => 'invalid', 'message' => __( 'The q parameter is required and must be at most 200 characters.' ) ],
            ] );
        }

        $max     = max( 1, (int) config( 'artisanpack.ecommerce.api.max_per_page', 100 ) );
        $perPage = min( $max, max( 1, (int) $request->query( 'per_page', (string) config( 'artisanpack.ecommerce.api.default_per_page', 25 ) ) ) );
        $page    = max( 1, (int) $request->query( 'page', '1' ) );

        // Deep paging makes a search engine scan the whole catalog.
        if ( $page * $perPage > self::MAX_RESULTS ) {
            return Problem::make( 422, 'validation-failed', __( 'Validation failed' ), __( 'Search results are available up to :max deep.', [ 'max' => self::MAX_RESULTS ] ), $request, [
                [ 'field' => 'page', 'code' => 'invalid', 'message' => __( 'Search results are available up to :max deep.', [ 'max' => self::MAX_RESULTS ] ) ],
            ] );
        }

        $filters = $request->query( 'filter', [] );
        $filters = array_intersect_key( is_array( $filters ) ? $filters : [], array_flip( ProductController::CATALOG_FILTERS ) );

        $filters['attributes'] = is_array( $request->query( 'attributes' ) ) ? $request->query( 'attributes' ) : [];

        $sort     = $request->query( 'sort' );
        $currency = $request->query( 'currency' );

        $result = app( SearchProviderRegistry::class )->active()->search( new SearchQuery(
            $term,
            $filters,
            is_string( $sort ) && in_array( $sort, CatalogQuery::SORTS, true ) ? $sort : null,
            $page,
            $perPage,
            is_string( $currency ) && 1 === preg_match( '/^[A-Za-z]{3}$/', $currency ) ? strtoupper( $currency ) : null,
            // Same includes as `GET products` (current prices only).
            ListQuery::includes( $request, app( ProductController::class )->publicIncludes() ),
        ) );

        $payload         = ProductResource::collection( $result->paginator()->appends( $request->query() ) )->response( $request )->getData( true );
        $payload['data'] = (array) applyFilters( 'ap.ecommerce.api.list.' . ProductResource::NAME, $payload['data'], Product::query()->storefrontVisible(), $request );

        $payload['meta']['facets']      = $result->facets;
        $payload['meta']['suggestions'] = $result->suggestions;

        return new JsonResponse( $payload );
    }
}
