<?php

/**
 * ProductCategoryController.
 *
 * `admin/product-categories` (engine spec §9.5): list, create, update,
 * delete, and reorder within a parent, through {@see ProductCategoryService}.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductCategoryRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ReorderRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductCategoryResource;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\ProductCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductCategoryController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  ProductCategoryService  $categories  Category writes.
     */
    public function __construct( private readonly ProductCategoryService $categories )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List product categories', resource: ProductCategoryResource::class, collection: true )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            ProductCategory::query(),
            $request,
            ProductCategoryResource::class,
            [
                'parent_id' => [ 'parent_id', 'int' ],
                'slug'      => 'slug',
            ],
            [ 'name' => 'name', 'position' => 'position' ],
            [ 'parent' => 'parent', 'children' => 'children' ],
            'position',
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductCategoryRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create a product category', resource: ProductCategoryResource::class, status: 201 )]
    public function store( ProductCategoryRequest $request ): JsonResponse
    {
        return $this->resourceResponse( $this->categories->create( $request->validated() ), $request, ProductCategoryResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductCategoryRequest  $request   Validated request.
     * @param  ProductCategory         $category  Category.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a product category', resource: ProductCategoryResource::class )]
    public function update( ProductCategoryRequest $request, ProductCategory $category ): JsonResponse
    {
        return $this->resourceResponse( $this->categories->update( $category, $request->validated() ), $request, ProductCategoryResource::class );
    }

    /**
     * Deletes a category; its children move up to its parent.
     *
     * @since 1.0.0
     *
     * @param  Request          $request   Request.
     * @param  ProductCategory  $category  Category.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a product category', resource: ProductCategoryResource::class )]
    public function destroy( Request $request, ProductCategory $category ): JsonResponse
    {
        $this->categories->delete( $category );

        return $this->resourceResponse( $category, $request, ProductCategoryResource::class );
    }

    /**
     * @since 1.0.0
     *
     * @param  ReorderRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Reorder categories within a parent', resource: ProductCategoryResource::class, collection: true )]
    public function reorder( ReorderRequest $request ): JsonResponse
    {
        $parentId = $request->validated( 'parent_id' );

        return ProductCategoryResource::collection(
            $this->categories->reorder( null === $parentId ? null : (int) $parentId, (array) $request->validated( 'ids' ) ),
        )->response( $request );
    }
}
