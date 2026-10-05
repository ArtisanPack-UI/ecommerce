<?php

/**
 * SearchController.
 *
 * `GET search?q=` — storefront product search through Laravel Scout
 * (engine spec §9.1). Uses whichever engine `Product::searchableUsing()`
 * resolves (the `database` driver by default), limited to storefront-
 * visible products. Results are page-number paginated (`page`,
 * `per_page`) because Scout engines don't support cursors.
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

use ArtisanPackUI\Ecommerce\Http\Resources\ProductResource;
use ArtisanPackUI\Ecommerce\Http\Support\ListQuery;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
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
    #[ApiOperation( summary: 'Search products', resource: ProductResource::class, collection: true, description: 'Full-text product search through Laravel Scout. Page-number paginated via `page` and `per_page`.' )]
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

        // `status` is filtered by the engine; `query()` then drops anything
        // scheduled for later publication.
        // Same includes as `GET products` (current prices only).
        $with = ListQuery::includes( $request, app( ProductController::class )->publicIncludes() );

        $paginator = Product::search( $term )
            ->where( 'status', 'active' )
            ->query( static fn ( $query ) => $query->storefrontVisible()->with( $with ) )
            ->paginate( $perPage, 'page', $page );

        $payload         = ProductResource::collection( $paginator )->response( $request )->getData( true );
        $payload['data'] = (array) applyFilters( 'ap.ecommerce.api.list.' . ProductResource::NAME, $payload['data'], Product::query()->storefrontVisible(), $request );

        return new JsonResponse( $payload );
    }
}
