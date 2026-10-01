<?php

/**
 * ProductAttributeController.
 *
 * `admin/products/{product}/attributes` (engine spec §9.5): create,
 * update (with its values), and delete an attribute definition.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductAttributeRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductAttributeResource;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductAttribute;
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
class ProductAttributeController extends ApiController
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
     * @param  ProductAttributeRequest  $request  Validated request.
     * @param  Product                  $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create a product attribute', resource: ProductAttributeResource::class, status: 201 )]
    public function store( ProductAttributeRequest $request, Product $product ): JsonResponse
    {
        return $this->resourceResponse( $this->products->createAttribute( $product, $request->validated() ), $request, ProductAttributeResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductAttributeRequest  $request    Validated request.
     * @param  Product                  $product    Product.
     * @param  ProductAttribute         $attribute  Attribute.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a product attribute', resource: ProductAttributeResource::class )]
    public function update( ProductAttributeRequest $request, Product $product, ProductAttribute $attribute ): JsonResponse
    {
        abort_unless( (int) $attribute->product_id === (int) $product->id, 404 );

        return $this->resourceResponse( $this->products->updateAttribute( $attribute, $request->validated() ), $request, ProductAttributeResource::class );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request           $request    Request.
     * @param  Product           $product    Product.
     * @param  ProductAttribute  $attribute  Attribute.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a product attribute', resource: ProductAttributeResource::class )]
    public function destroy( Request $request, Product $product, ProductAttribute $attribute ): JsonResponse
    {
        abort_unless( (int) $attribute->product_id === (int) $product->id, 404 );

        $this->products->deleteAttribute( $attribute );

        return $this->resourceResponse( $attribute, $request, ProductAttributeResource::class );
    }
}
