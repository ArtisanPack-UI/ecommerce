<?php

/**
 * ProductPriceController.
 *
 * `admin/products/{product}/prices` (engine spec §9.5): upsert one
 * per-currency price row for the product or one of its variants, and
 * update or delete an existing row. A row is only reachable through the
 * product it (or its variant) belongs to.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductPriceRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductPriceResource;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
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
class ProductPriceController extends ApiController
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
     * Creates or updates the row for a currency and schedule.
     *
     * @since 1.0.0
     *
     * @param  ProductPriceRequest  $request  Validated request.
     * @param  Product              $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Set a product or variant price', resource: ProductPriceResource::class, status: 201 )]
    public function store( ProductPriceRequest $request, Product $product ): JsonResponse
    {
        $this->products->assertEditable( $product );

        $data      = $request->validated();
        $variantId = $data['product_variant_id'] ?? null;
        $priceable = null === $variantId
            ? $product
            : ProductVariant::query()->where( 'product_id', $product->id )->findOrFail( (int) $variantId );

        unset( $data['product_variant_id'] );

        return $this->resourceResponse( $this->products->upsertPrice( $priceable, $data ), $request, ProductPriceResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductPriceRequest  $request  Validated request.
     * @param  Product              $product  Product.
     * @param  ProductPrice         $price    Price row.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a price row', resource: ProductPriceResource::class )]
    public function update( ProductPriceRequest $request, Product $product, ProductPrice $price ): JsonResponse
    {
        $this->ensureBelongs( $product, $price );
        $this->products->assertEditable( $product );

        $data = $request->validated();
        unset( $data['product_variant_id'] );

        return $this->resourceResponse( $this->products->updatePrice( $price, $data ), $request, ProductPriceResource::class );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request       $request  Request.
     * @param  Product       $product  Product.
     * @param  ProductPrice  $price    Price row.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a price row', resource: ProductPriceResource::class )]
    public function destroy( Request $request, Product $product, ProductPrice $price ): JsonResponse
    {
        $this->ensureBelongs( $product, $price );
        $this->products->assertEditable( $product );

        $price->delete();

        return $this->resourceResponse( $price, $request, ProductPriceResource::class );
    }

    /**
     * 404s unless the row belongs to the product or one of its variants.
     *
     * @since 1.0.0
     *
     * @param  Product       $product  Product.
     * @param  ProductPrice  $price    Price row.
     *
     * @return void
     */
    private function ensureBelongs( Product $product, ProductPrice $price ): void
    {
        $belongs = match ( $price->priceable_type ) {
            $product->getMorphClass()                 => (int) $price->priceable_id === (int) $product->id,
            ( new ProductVariant() )->getMorphClass() => ProductVariant::query()->whereKey( $price->priceable_id )->where( 'product_id', $product->id )->exists(),
            default                                   => false,
        };

        abort_unless( $belongs, 404 );
    }
}
