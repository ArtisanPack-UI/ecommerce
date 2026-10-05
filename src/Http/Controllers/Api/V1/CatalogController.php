<?php

/**
 * CatalogController.
 *
 * Public category and tag reads (engine spec §9.1; #171): the category tree
 * (`GET categories`), one category by id or slug (`GET categories/{category}`),
 * the products in a category and its sub-categories
 * (`GET categories/{category}/products`, with every `GET products` filter,
 * sort, and include), and the tags (`GET tags`) with how many visible
 * products carry each.
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

use ArtisanPackUI\Ecommerce\Catalog\CategoryTree;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductCategoryResource;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductResource;
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\OpenApi\CatalogParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CatalogController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  CategoryTree  $categories  Category reads.
     */
    public function __construct(
        private readonly CategoryTree $categories,
    ) {
    }

    /**
     * The whole category tree, nested under `children`.
     *
     * @since 1.0.0
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Get the category tree' )]
    public function categories(): JsonResponse
    {
        return new JsonResponse( [ 'data' => $this->categories->tree() ] );
    }

    /**
     * One category by id or slug (`include=parent,children`).
     *
     * @since 1.0.0
     *
     * @param  Request  $request   Request.
     * @param  string   $category  Id or slug.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Get a category', resource: ProductCategoryResource::class, includes: [ 'parent', 'children' ] )]
    public function category( Request $request, string $category ): JsonResponse
    {
        $model = $this->categories->find( $category );

        abort_if( null === $model, 404 );

        return $this->resourceResponse( $model, $request, ProductCategoryResource::class, [ 'parent' => 'parent', 'children' => 'children' ] );
    }

    /**
     * The visible products in a category and its sub-categories — `GET
     * products` with `filter[category]` set.
     *
     * @since 1.0.0
     *
     * @param  Request  $request   Request.
     * @param  string   $category  Id or slug.
     *
     * @return JsonResponse
     */
    #[ApiOperation(
        summary: 'List the products in a category',
        resource: ProductResource::class,
        collection: true,
        filters: ProductController::OPENAPI_FILTERS,
        sorts: ProductController::OPENAPI_SORTS,
        includes: ProductController::OPENAPI_INCLUDES,
        query: CatalogParameters::PRODUCT_LIST,
    )]
    public function categoryProducts( Request $request, string $category ): JsonResponse
    {
        $model = $this->categories->find( $category );

        abort_if( null === $model, 404 );

        $query           = $request->query->all();
        $query['filter'] = [ ...( is_array( $query['filter'] ?? null ) ? $query['filter'] : [] ), 'category' => (string) $model->id ];

        $listing = $request->duplicate( $query );
        $listing->setUserResolver( $request->getUserResolver() );
        $listing->setRouteResolver( $request->getRouteResolver() );

        return app( ProductController::class )->index( $listing );
    }

    /**
     * Every tag, by name, with how many visible products carry it.
     *
     * @since 1.0.0
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List product tags' )]
    public function tags(): JsonResponse
    {
        $tags = ProductTag::query()
            ->withCount( [ 'products as products_count' => static fn ( Builder $products ) => $products->storefrontVisible() ] )
            ->orderBy( 'name' )
            ->get()
            ->map( static fn ( ProductTag $tag ): array => [ 'id' => (int) $tag->id, 'name' => (string) $tag->name, 'slug' => (string) $tag->slug, 'products_count' => (int) $tag->products_count ] )
            ->all();

        return new JsonResponse( [ 'data' => $tags ] );
    }
}
