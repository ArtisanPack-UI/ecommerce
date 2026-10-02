<?php

/**
 * ProductAdminController.
 *
 * `admin/products` (engine spec §9.5): admin reads that include drafts and
 * archived products, plus create, update, and delete through
 * {@see ProductService}. A create or update may carry the product's related
 * sets (prices, categories, tags, gallery, attributes, children, stock
 * settings) so a whole product form saves in one call.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductResource;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\ProductService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductAdminController extends ApiController
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
     * Lists every product, whatever its status.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List products (admin)', resource: ProductResource::class, collection: true )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            Product::query(),
            $request,
            ProductResource::class,
            [
                'type'   => 'type',
                'status' => 'status',
                'sku'    => 'sku',
                'slug'   => 'slug',
                'search' => static fn ( Builder $query, string $term ) => $query->where( static function ( Builder $inner ) use ( $term ): void {
                    $like = '%' . str_replace( [ '!', '%', '_' ], [ '!!', '!%', '!_' ], $term ) . '%';

                    $inner->whereRaw( "name LIKE ? ESCAPE '!'", [ $like ] )->orWhereRaw( "sku LIKE ? ESCAPE '!'", [ $like ] );
                } ),
            ],
            [ 'name' => 'name', 'created_at' => 'created_at', 'updated_at' => 'updated_at' ],
            self::includes(),
        );
    }

    /**
     * Shows one product, whatever its status.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  Product  $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Get a product (admin)', resource: ProductResource::class )]
    public function show( Request $request, Product $product ): JsonResponse
    {
        return $this->resourceResponse( $product, $request, ProductResource::class, self::includes() );
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create a product', resource: ProductResource::class, status: 201 )]
    public function store( ProductRequest $request ): JsonResponse
    {
        return $this->resourceResponse( $this->products->create( $request->validated() ), $request, ProductResource::class, self::includes(), 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductRequest  $request  Validated request.
     * @param  Product         $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a product', resource: ProductResource::class )]
    public function update( ProductRequest $request, Product $product ): JsonResponse
    {
        if ( $request->has( 'stock_adjustment' ) && null !== ( $denied = $this->forbiddenUnless( $request, 'inventory', 'adjust' ) ) ) {
            return $denied;
        }

        return $this->resourceResponse( $this->products->update( $product, $request->validated() ), $request, ProductResource::class, self::includes() );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  Product  $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a product', resource: ProductResource::class )]
    public function destroy( Request $request, Product $product ): JsonResponse
    {
        $this->products->delete( $product );

        return $this->resourceResponse( $product, $request, ProductResource::class );
    }

    /**
     * Relations an admin product response may include.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public static function includes(): array
    {
        return [
            'variants'          => 'variants',
            'variants.prices'   => 'variants.prices',
            'prices'            => 'prices',
            'attributes'        => 'productAttributes',
            'attributes.values' => 'productAttributes.values',
            'categories'        => 'categories',
            'tags'              => 'tags',
            'images'            => 'images',
            'children'          => 'children',
        ];
    }
}
