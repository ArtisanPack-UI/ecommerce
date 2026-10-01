<?php

/**
 * ProductImageController.
 *
 * `admin/products/{product}/images` (engine spec §9.5): add, update,
 * remove, and reorder gallery images. Each image points at a media-library
 * item or, when that package is absent, a plain `image_url`.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductImageRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ReorderRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductImageResource;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductImage;
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
class ProductImageController extends ApiController
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
     * @param  ProductImageRequest  $request  Validated request.
     * @param  Product              $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Add a gallery image', resource: ProductImageResource::class, status: 201 )]
    public function store( ProductImageRequest $request, Product $product ): JsonResponse
    {
        return $this->resourceResponse( $this->products->addImage( $product, $request->validated() ), $request, ProductImageResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductImageRequest  $request  Validated request.
     * @param  Product              $product  Product.
     * @param  ProductImage         $image    Image (scoped to the product).
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a gallery image', resource: ProductImageResource::class )]
    public function update( ProductImageRequest $request, Product $product, ProductImage $image ): JsonResponse
    {
        return $this->resourceResponse( $this->products->updateImage( $image, $request->validated() ), $request, ProductImageResource::class );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request       $request  Request.
     * @param  Product       $product  Product.
     * @param  ProductImage  $image    Image (scoped to the product).
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Remove a gallery image', resource: ProductImageResource::class )]
    public function destroy( Request $request, Product $product, ProductImage $image ): JsonResponse
    {
        $this->products->removeImage( $image );

        return $this->resourceResponse( $image, $request, ProductImageResource::class );
    }

    /**
     * @since 1.0.0
     *
     * @param  ReorderRequest  $request  Validated request.
     * @param  Product         $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Reorder the gallery', resource: ProductImageResource::class, collection: true )]
    public function reorder( ReorderRequest $request, Product $product ): JsonResponse
    {
        return ProductImageResource::collection( $this->products->reorderImages( $product, (array) $request->validated( 'ids' ) ) )->response( $request );
    }
}
