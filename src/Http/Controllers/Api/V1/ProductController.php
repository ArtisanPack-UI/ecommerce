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

use ArtisanPackUI\Ecommerce\Catalog\CatalogQuery;
use ArtisanPackUI\Ecommerce\Catalog\ProductViews;
use ArtisanPackUI\Ecommerce\Catalog\RelatedProducts;
use ArtisanPackUI\Ecommerce\Catalog\VariantResolver;
use ArtisanPackUI\Ecommerce\Contracts\ProvidesStorefrontOptions;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\PurchaseOptionsRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductResource;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductVariantResource;
use ArtisanPackUI\Ecommerce\Http\Support\ListQuery;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Inventory\StockStatus;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductRelation;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\OpenApi\CatalogParameters;
use ArtisanPackUI\Ecommerce\Pricing\PriceDisplayResolver;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use ArtisanPackUI\Ecommerce\Services\StoreCurrencies;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
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
     * Filters handled by {@see CatalogQuery}.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const CATALOG_FILTERS = [ 'search', 'category', 'descendants', 'tag', 'price_min', 'price_max', 'in_stock', 'on_sale', 'featured', 'min_rating', 'ids' ];

    /**
     * Sorts computed by {@see CatalogQuery} (page-paginated).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const COMPUTED_SORTS = [ 'price', '-price', 'popularity', 'relevance', 'newest' ];

    /**
     * `filter[...]` keys and their types, for the OpenAPI document.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const OPENAPI_FILTERS = [
        'type'        => 'string',
        'sku'         => 'string',
        'slug'        => 'string',
        'search'      => 'string',
        'category'    => 'string',
        'descendants' => 'boolean',
        'tag'         => 'string',
        'price_min'   => 'integer',
        'price_max'   => 'integer',
        'in_stock'    => 'boolean',
        'on_sale'     => 'boolean',
        'featured'    => 'boolean',
        'min_rating'  => 'number',
        'ids'         => 'int-list',
    ];

    /**
     * Accepted `sort` values, for the OpenAPI document.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const OPENAPI_SORTS = [ 'position', 'name', 'created_at', 'rating', 'price', '-price', 'popularity', 'relevance', 'newest' ];

    /**
     * Accepted `include` values, for the OpenAPI document.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const OPENAPI_INCLUDES = [ 'variants', 'variants.prices', 'prices', 'attributes', 'attributes.values', 'images', 'categories', 'tags' ];

    /**
     * Storefront products through {@see CatalogQuery}.
     *
     * Filters (`filter[...]`): `type`, `sku`, `slug`, `search`, `category`
     * (id or slug; `descendants=0` for that category only), `tag`,
     * `price_min` / `price_max` (minor units of `currency`), `in_stock`,
     * `on_sale`, `featured`, `min_rating`, `ids`; attribute values as
     * `attributes[key]=value1,value2`. `q` is the same as `filter[search]`.
     *
     * Sorts: `name`, `created_at`, `position` (default), `rating` —
     * cursor-paginated; `price`, `-price`, `popularity`, `relevance`, and
     * `newest` are computed and page-paginated (`page`). `facets=1` adds
     * `meta.facets` ({@see CatalogQuery::facets()}).
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation(
        summary: 'List storefront products',
        resource: ProductResource::class,
        collection: true,
        filters: self::OPENAPI_FILTERS,
        sorts: self::OPENAPI_SORTS,
        includes: self::OPENAPI_INCLUDES,
        query: CatalogParameters::PRODUCT_LIST,
    )]
    public function index( Request $request ): JsonResponse
    {
        $filters  = $request->query( 'filter', [] );
        $filters  = is_array( $filters ) ? $filters : [];
        $sort     = is_string( $request->query( 'sort' ) ) ? (string) $request->query( 'sort' ) : '';
        $computed = in_array( $sort, self::COMPUTED_SORTS, true );
        $currency = $request->query( 'currency' );

        $catalogFilters = array_intersect_key( $filters, array_flip( self::CATALOG_FILTERS ) );

        if ( is_string( $request->query( 'q' ) ) && '' !== trim( (string) $request->query( 'q' ) ) ) {
            $catalogFilters['search'] = (string) $request->query( 'q' );
        }

        $catalogFilters['attributes'] = is_array( $request->query( 'attributes' ) ) ? $request->query( 'attributes' ) : [];

        if ( null !== $currency && ( ! is_string( $currency ) || 1 !== preg_match( '/^[A-Za-z]{3}$/', $currency ) ) ) {
            return Problem::make( 400, 'invalid-parameter', __( 'Invalid parameter' ), __( 'currency must be a three-letter ISO 4217 code.' ), $request, [
                [ 'field' => 'currency', 'code' => 'invalid', 'message' => __( 'currency must be a three-letter ISO 4217 code.' ) ],
            ] );
        }

        $catalog = app( CatalogQuery::class )->fromParameters( $catalogFilters, $computed ? $sort : null, is_string( $currency ) ? $currency : null );
        $query   = $catalog->builder( $computed );

        $query->with( ListQuery::includes( $request, $this->includes() ) );

        // Validates every filter (catalog ones are already applied) and, for
        // column sorts, orders the query.
        $listRequest = $computed ? $request->duplicate( array_diff_key( $request->query->all(), [ 'sort' => true ] ) ) : $request;
        $passThrough = static fn (): null => null;

        ListQuery::apply(
            $query,
            $listRequest,
            [ 'type' => 'type', 'sku' => 'sku', 'slug' => 'slug', ...array_fill_keys( self::CATALOG_FILTERS, $passThrough ) ],
            [ 'name' => 'name', 'created_at' => 'created_at', 'position' => 'position', 'rating' => 'avg_rating' ],
            $computed ? '-id' : 'position',
        );

        $perPage   = min( max( 1, (int) config( 'artisanpack.ecommerce.api.max_per_page', 100 ) ), max( 1, (int) $request->query( 'per_page', (string) config( 'artisanpack.ecommerce.api.default_per_page', 25 ) ) ) );
        $paginator = $computed ? $query->paginate( $perPage )->withQueryString() : ListQuery::paginate( $query, $request );
        $payload   = ProductResource::collection( $paginator )->response( $request )->getData( true );

        $payload['data'] = (array) applyFilters( 'ap.ecommerce.api.list.' . ProductResource::NAME, $payload['data'], $query, $request );

        if ( in_array( (string) $request->query( 'facets' ), [ '1', 'true' ], true ) ) {
            $payload['meta']['facets'] = $catalog->facets();
        }

        return new JsonResponse( $payload );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  int      $product  Product id.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Get a storefront product', resource: ProductResource::class, includes: self::OPENAPI_INCLUDES )]
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
    #[ApiOperation( summary: 'List the variants of a product', resource: ProductVariantResource::class, collection: true, filters: [ 'sku' => 'string' ], sorts: [ 'position' ], includes: [ 'prices' ] )]
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
     * What a product page needs to sell `$product` (#172): its display
     * price (with tax at the destination, default the store's country),
     * its stock status, and, for variable products, every variant with
     * its attribute values, availability, price, and image.
     *
     * @since 1.0.0
     *
     * @param  PurchaseOptionsRequest  $request  Validated request.
     * @param  int                     $product  Product id.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Get a product\'s price, stock, and variant options' )]
    public function purchaseOptions( PurchaseOptionsRequest $request, int $product ): JsonResponse
    {
        $model       = $this->visible()->findOrFail( $product );
        $currency    = strtoupper( (string) ( $request->validated( 'currency' ) ?? app( StoreCurrencies::class )->base() ) );
        $country     = $request->validated( 'country_code' );
        $destination = null === $country ? null : Address::fromArray( [
            'country_code' => $country,
            'region_code'  => $request->validated( 'region_code' ),
            'postal_code'  => $request->validated( 'postal_code' ),
        ] );

        return new JsonResponse( [ 'data' => [
            'product_id' => (int) $model->id,
            'currency'   => $currency,
            'price'      => app( PriceDisplayResolver::class )->for( $model, $currency, $destination )?->toArray(),
            'stock'      => StockStatus::for( $model )->toArray(),
            'variants'   => app( VariantResolver::class )->matrix( $model, $currency ),
            'options'    => ! $model->typeIsMissing() && $model->productType() instanceof ProvidesStorefrontOptions ? $model->productType()->storefrontOptions( $model ) : [],
        ] ] );
    }

    /**
     * Upsells, cross-sells, or related products for `$product` (#182):
     * hand-picked first, `related` filled up from shared categories and
     * tags. `type` defaults to `related`; `limit` to 8 (at most 50).
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  int      $product  Product id.
     *
     * @return JsonResponse
     */
    #[ApiOperation(
        summary: 'List related products, upsells, or cross-sells',
        resource: ProductResource::class,
        collection: true,
        includes: self::OPENAPI_INCLUDES,
        query: [
            'type'  => [ 'schema' => [ 'type' => 'string', 'enum' => [ 'related', 'upsell', 'cross_sell' ] ] ],
            'limit' => [ 'schema' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ] ],
        ],
    )]
    public function related( Request $request, int $product ): JsonResponse
    {
        $model = $this->visible()->findOrFail( $product );
        $type  = (string) $request->query( 'type', ProductRelation::RELATED );

        if ( ! in_array( $type, ProductRelation::TYPES, true ) ) {
            return Problem::make( 400, 'invalid-parameter', __( 'Invalid parameter' ), __( 'type must be one of: :types.', [ 'types' => implode( ', ', ProductRelation::TYPES ) ] ), $request );
        }

        $products = app( RelatedProducts::class )->for( $model, $type, (int) $request->query( 'limit', '8' ), ListQuery::includes( $request, $this->includes() ) );

        return ProductResource::collection( $products )->response( $request );
    }

    /**
     * Records that the shopper viewed `$product` (#179): fires
     * `ap.ecommerce.product.viewed` for satellites such as recently-viewed.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  int      $product  Product id.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Record a product view', status: 202 )]
    public function recordView( Request $request, int $product ): JsonResponse
    {
        $model    = $this->visible()->findOrFail( $product );
        $user     = $request->user();
        $customer = null === $user ? null : app( CustomerService::class )->customerForUser( $user );

        ProductViews::record( $model, $customer );

        return new JsonResponse( [ 'data' => [ 'recorded' => true ] ], 202 );
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
    public function publicIncludes(): array
    {
        return $this->includes();
    }

    /**
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
            'images'            => 'images',
            'categories'        => 'categories',
            'tags'              => 'tags',
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
        return Product::query()->storefrontVisible();
    }
}
