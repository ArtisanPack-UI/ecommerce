<?php

/**
 * ProductLinkController.
 *
 * The product's links and stock (engine spec §9.5):
 * `POST admin/products/{product}/categories` and `…/tags` (sync, attach, or
 * detach ids), `POST …/children` (replace a grouped or bundled product's
 * members), and `POST …/stock` (an audited stock change).
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\AdjustStockRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductChildrenRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductLinksRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\InventoryItemResource;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductCategoryResource;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductChildResource;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductTagResource;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\ProductService;
use Illuminate\Http\JsonResponse;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductLinkController extends ApiController
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
     * @param  ProductLinksRequest  $request  Validated request.
     * @param  Product              $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Set a product\'s categories', resource: ProductCategoryResource::class, collection: true )]
    public function categories( ProductLinksRequest $request, Product $product ): JsonResponse
    {
        $this->products->assertEditable( $product );

        $categories = $this->products->setCategories( $product, (array) $request->validated( 'ids' ), (string) ( $request->validated( 'mode' ) ?? 'sync' ) );

        return ProductCategoryResource::collection( $categories )->response( $request );
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductLinksRequest  $request  Validated request.
     * @param  Product              $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Set a product\'s tags', resource: ProductTagResource::class, collection: true )]
    public function tags( ProductLinksRequest $request, Product $product ): JsonResponse
    {
        $this->products->assertEditable( $product );

        $tags = $this->products->setTags( $product, (array) $request->validated( 'ids' ), (string) ( $request->validated( 'mode' ) ?? 'sync' ) );

        return ProductTagResource::collection( $tags )->response( $request );
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductChildrenRequest  $request  Validated request.
     * @param  Product                 $product  Grouped or bundled product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Replace a grouped or bundled product\'s members', resource: ProductChildResource::class, collection: true )]
    public function children( ProductChildrenRequest $request, Product $product ): JsonResponse
    {
        $this->products->assertEditable( $product );

        return ProductChildResource::collection( $this->products->syncChildren( $product, (array) $request->validated( 'children' ) ) )->response( $request );
    }

    /**
     * @since 1.0.0
     *
     * @param  AdjustStockRequest  $request  Validated request.
     * @param  Product             $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Adjust a product or variant\'s stock', resource: InventoryItemResource::class )]
    public function stock( AdjustStockRequest $request, Product $product ): JsonResponse
    {
        $this->products->assertEditable( $product );

        $variantId = $request->validated( 'product_variant_id' );
        $stockable = null === $variantId
            ? $product
            : ProductVariant::query()->where( 'product_id', $product->id )->findOrFail( (int) $variantId );

        $item = $this->products->adjustStock( $stockable, (int) $request->validated( 'delta' ), (string) $request->validated( 'reason' ), 'stock' );

        return $this->resourceResponse( $item, $request, InventoryItemResource::class );
    }
}
