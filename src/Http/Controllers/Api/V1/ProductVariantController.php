<?php

/**
 * ProductVariantController.
 *
 * `admin/products/{product}/variants` (engine spec §9.5): create, update,
 * delete, reorder, and "generate from the attribute matrix" through
 * {@see ProductService}.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\GenerateVariantsRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductVariantRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ReorderRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductVariantResource;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductVariantController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  ProductService  $products  Product writes.
     */
    public function __construct( private readonly ProductService $products )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductVariantRequest  $request  Validated request.
     * @param  Product                $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create a product variant', resource: ProductVariantResource::class, status: 201 )]
    public function store( ProductVariantRequest $request, Product $product ): JsonResponse
    {
        return $this->resourceResponse( $this->products->createVariant( $product, $request->validated() ), $request, ProductVariantResource::class, [ 'prices' => 'prices' ], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductVariantRequest  $request  Validated request.
     * @param  Product                $product  Product.
     * @param  ProductVariant         $variant  Variant (scoped to the product).
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a product variant', resource: ProductVariantResource::class )]
    public function update( ProductVariantRequest $request, Product $product, ProductVariant $variant ): JsonResponse
    {
        return $this->resourceResponse( $this->products->updateVariant( $variant, $request->validated() ), $request, ProductVariantResource::class, [ 'prices' => 'prices' ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request         $request  Request.
     * @param  Product         $product  Product.
     * @param  ProductVariant  $variant  Variant (scoped to the product).
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a product variant', resource: ProductVariantResource::class )]
    public function destroy( Request $request, Product $product, ProductVariant $variant ): JsonResponse
    {
        $this->products->deleteVariant( $variant );

        return $this->resourceResponse( $variant, $request, ProductVariantResource::class );
    }

    /**
     * Creates every missing variant of the attribute matrix.
     *
     * @since 1.0.0
     *
     * @param  GenerateVariantsRequest  $request  Validated request (defaults for new variants).
     * @param  Product                  $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Generate variants from the attribute matrix', resource: ProductVariantResource::class, collection: true, status: 201 )]
    public function generate( GenerateVariantsRequest $request, Product $product ): JsonResponse
    {
        $created = $this->products->generateVariants( $product, $request->validated() );

        return ProductVariantResource::collection( $created )->response( $request )->setStatusCode( 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  ReorderRequest  $request  Validated request.
     * @param  Product         $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Reorder product variants', resource: ProductVariantResource::class, collection: true )]
    public function reorder( ReorderRequest $request, Product $product ): JsonResponse
    {
        $variants = $this->products->reorderVariants( $product, (array) $request->validated( 'ids' ) );

        return ProductVariantResource::collection( $variants )->response( $request );
    }
}
