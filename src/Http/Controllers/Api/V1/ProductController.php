<?php

/**
 * ProductController.
 *
 * Public catalog: `GET products`, `GET products/{product}`,
 * `GET products/{product}/variants` (engine spec §9.1). Only `active`
 * products whose `published_at` is null or in the past are visible.
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
use ArtisanPackUI\Ecommerce\Http\Resources\ProductVariantResource;
use ArtisanPackUI\Ecommerce\Models\Product;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            $this->visible(),
            $request,
            ProductResource::class,
            [
                'type'   => 'type',
                'sku'    => 'sku',
                'slug'   => 'slug',
                'search' => static fn ( Builder $query, string $term ) => $query->whereRaw(
                    "name LIKE ? ESCAPE '!'",
                    [ '%' . str_replace( [ '!', '%', '_' ], [ '!!', '!%', '!_' ], $term ) . '%' ],
                ),
            ],
            [ 'name' => 'name', 'created_at' => 'created_at' ],
            $this->includes(),
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  int      $product  Product id.
     *
     * @return JsonResponse
     */
    public function show( Request $request, int $product ): JsonResponse
    {
        return $this->resourceResponse( $this->visible()->findOrFail( $product ), $request, ProductResource::class, $this->includes() );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  int      $product  Product id.
     *
     * @return JsonResponse
     */
    public function variants( Request $request, int $product ): JsonResponse
    {
        $model = $this->visible()->findOrFail( $product );

        return $this->listResponse(
            $model->variants()->getQuery(),
            $request,
            ProductVariantResource::class,
            [ 'sku' => 'sku' ],
            [ 'position' => 'position' ],
            [ 'prices' => [ 'prices', static fn ( $query ) => $query->currentAt( Carbon::now() ) ] ],
            'position',
        );
    }

    /**
     * Includes a client may request. Price rows are limited to those
     * current now, so scheduled (unannounced) and expired prices stay
     * private on this public endpoint.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: string, 1: Closure}|string>
     */
    protected function includes(): array
    {
        $current = static fn ( $query ) => $query->currentAt( Carbon::now() );

        return [
            'variants'          => 'variants',
            'variants.prices'   => [ 'variants.prices', $current ],
            'prices'            => [ 'prices', $current ],
            'attributes'        => 'productAttributes',
            'attributes.values' => 'productAttributes.values',
        ];
    }

    /**
     * Storefront-visible products.
     *
     * @since 1.0.0
     *
     * @return Builder<Product>
     */
    protected function visible(): Builder
    {
        return Product::query()
            ->where( 'status', 'active' )
            ->where( fn ( Builder $q ) => $q->whereNull( 'published_at' )->orWhere( 'published_at', '<=', Carbon::now() ) );
    }
}
