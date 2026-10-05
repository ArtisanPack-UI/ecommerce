<?php

/**
 * CatalogQuery.
 *
 * The storefront product query every surface shares (#171) — the REST
 * catalog, GraphQL, and in-process storefronts. Start one with
 * `app( CatalogQuery::class )`, chain filters and a sort, then take the
 * Eloquent {@see self::builder()} (paginate it however you like) and, when
 * the page shows filters, {@see self::facets()}.
 *
 * Only storefront-visible products are ever returned. Filters:
 * category (with its descendants by default), tag, price range in a
 * currency, attribute values, in stock, on sale, minimum rating, featured,
 * a search term, and hand-picked ids. Sorts: relevance, newest, price
 * (ascending or descending), popularity (units sold), rating, name, and
 * manual position.
 *
 * Prices are a product's lowest current price — its own or a variant's —
 * in the requested currency; products priced only in the base currency are
 * converted at the current rate. Every built query runs through
 * `ap.ecommerce.product.listQuery` (filter: the builder and the
 * parameters).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Catalog;

use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductAttribute;
use ArtisanPackUI\Ecommerce\Models\ProductAttributeValue;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Registries\CurrencyRateProviderRegistry;
use ArtisanPackUI\Ecommerce\Reports\Report;
use ArtisanPackUI\Ecommerce\Services\StoreCurrencies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Money\Currency;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CatalogQuery
{
    /**
     * Supported sorts (a leading `-` reverses `price`).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const SORTS = [ 'relevance', 'newest', 'price', '-price', 'popularity', 'rating', 'name', 'position' ];

    /**
     * Most search hits a listing ranks.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_SEARCH_RESULTS = 1_000;

    /**
     * The filters and sort applied so far.
     *
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected array $params = [];

    /**
     * Search hits per term, so one query asks the engine once.
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, int>>
     */
    protected array $hits = [];

    /**
     * @since 1.0.0
     *
     * @param  CategoryTree                  $categories  Category descendants.
     * @param  StoreCurrencies               $currencies  Base currency.
     * @param  CurrencyRateProviderRegistry  $rates       Base → currency conversion for prices.
     */
    public function __construct(
        protected CategoryTree $categories,
        protected StoreCurrencies $currencies,
        protected CurrencyRateProviderRegistry $rates,
    ) {
    }

    /**
     * Builds a query from listing parameters (the REST `filter[...]`
     * values, `q`, `sort`, and `currency`): `category`, `tag`, `price_min`,
     * `price_max`, `attributes` (`key => value|values`), `in_stock`,
     * `on_sale`, `min_rating`, `featured`, `ids`, `search`.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $filters   Filters.
     * @param  string|null           $sort      Sort.
     * @param  string|null           $currency  Price currency (defaults to the base currency).
     *
     * @return static
     */
    public function fromParameters( array $filters, ?string $sort = null, ?string $currency = null ): static
    {
        if ( null !== $currency && '' !== $currency ) {
            $this->currency( $currency );
        }

        if ( isset( $filters['category'] ) && '' !== (string) $filters['category'] ) {
            $this->inCategory( (string) $filters['category'], ! in_array( (string) ( $filters['descendants'] ?? '1' ), [ '0', 'false' ], true ) );
        }

        if ( isset( $filters['tag'] ) && '' !== (string) $filters['tag'] ) {
            $this->withTag( (string) $filters['tag'] );
        }

        if ( isset( $filters['price_min'] ) || isset( $filters['price_max'] ) ) {
            $this->priceBetween(
                is_numeric( $filters['price_min'] ?? null ) ? (int) $filters['price_min'] : null,
                is_numeric( $filters['price_max'] ?? null ) ? (int) $filters['price_max'] : null,
            );
        }

        foreach ( (array) ( $filters['attributes'] ?? [] ) as $key => $values ) {
            $this->withAttribute( (string) $key, is_array( $values ) ? $values : explode( ',', (string) $values ) );
        }

        foreach ( [ 'in_stock' => 'inStock', 'on_sale' => 'onSale', 'featured' => 'featured' ] as $key => $method ) {
            if ( isset( $filters[ $key ] ) && in_array( (string) $filters[ $key ], [ '1', 'true' ], true ) ) {
                $this->{$method}();
            }
        }

        if ( isset( $filters['min_rating'] ) && is_numeric( $filters['min_rating'] ) ) {
            $this->minRating( (float) $filters['min_rating'] );
        }

        if ( isset( $filters['ids'] ) ) {
            $this->ids( array_map( 'intval', array_filter( is_array( $filters['ids'] ) ? $filters['ids'] : explode( ',', (string) $filters['ids'] ), 'is_numeric' ) ) );
        }

        if ( isset( $filters['search'] ) && '' !== trim( (string) $filters['search'] ) ) {
            $this->search( (string) $filters['search'] );
        }

        if ( null !== $sort && '' !== $sort ) {
            $this->sort( $sort );
        }

        return $this;
    }

    /**
     * Only products in `$category` (id or slug) — and, by default, its
     * descendants. An unknown category matches nothing.
     *
     * @since 1.0.0
     *
     * @param  int|string  $category     Category id or slug.
     * @param  bool        $descendants  Include sub-categories.
     *
     * @return static
     */
    public function inCategory( int|string $category, bool $descendants = true ): static
    {
        $model = $this->categories->find( $category );

        $this->params['category'] = null === $model ? [] : ( $descendants ? $this->categories->withDescendants( (int) $model->id ) : [ (int) $model->id ] );

        return $this;
    }

    /**
     * Only products with `$tag` (id or slug). An unknown tag matches nothing.
     *
     * @since 1.0.0
     *
     * @param  int|string  $tag  Tag id or slug.
     *
     * @return static
     */
    public function withTag( int|string $tag ): static
    {
        $model = is_int( $tag ) || ctype_digit( (string) $tag )
            ? ProductTag::query()->find( (int) $tag )
            : ProductTag::query()->where( 'slug', (string) $tag )->first();

        $this->params['tag'] = null === $model ? 0 : (int) $model->id;

        return $this;
    }

    /**
     * Prices in this currency (filters, sort, and facets).
     *
     * @since 1.0.0
     *
     * @param  string  $currency  ISO 4217 code.
     *
     * @throws InvalidArgumentException For a malformed code.
     *
     * @return static
     */
    public function currency( string $currency ): static
    {
        $currency = strtoupper( trim( $currency ) );

        if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
            throw new InvalidArgumentException( 'Currency must be a three-letter ISO 4217 code.' );
        }

        $this->params['currency'] = $currency;

        return $this;
    }

    /**
     * Only products whose lowest current price (minor units) is within the range.
     *
     * @since 1.0.0
     *
     * @param  int|null  $min  Minimum, inclusive.
     * @param  int|null  $max  Maximum, inclusive.
     *
     * @return static
     */
    public function priceBetween( ?int $min, ?int $max ): static
    {
        $this->params['price'] = [ $min, $max ];

        return $this;
    }

    /**
     * Only products whose attribute `$key` has one of `$values`.
     *
     * @since 1.0.0
     *
     * @param  string              $key     Attribute key.
     * @param  array<int, string>  $values  Accepted values.
     *
     * @return static
     */
    public function withAttribute( string $key, array $values ): static
    {
        $values = array_values( array_filter( array_map( static fn ( mixed $value ): string => trim( (string) $value ), $values ), static fn ( string $value ): bool => '' !== $value ) );

        if ( [] !== $values ) {
            $this->params['attributes'][ $key ] = $values;
        }

        return $this;
    }

    /**
     * Only products that can be bought now: untracked, backorderable, or
     * with stock left on the product or one of its variants.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function inStock(): static
    {
        $this->params['in_stock'] = true;

        return $this;
    }

    /**
     * Only products with a current price below its compare-at price.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function onSale(): static
    {
        $this->params['on_sale'] = true;

        return $this;
    }

    /**
     * Only products rated at least `$rating`.
     *
     * @since 1.0.0
     *
     * @param  float  $rating  Minimum average rating.
     *
     * @return static
     */
    public function minRating( float $rating ): static
    {
        $this->params['min_rating'] = max( 0.0, min( 5.0, $rating ) );

        return $this;
    }

    /**
     * Only featured products.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function featured(): static
    {
        $this->params['featured'] = true;

        return $this;
    }

    /**
     * Only these products (hand-picked).
     *
     * @since 1.0.0
     *
     * @param  array<int, int>  $ids  Product ids.
     *
     * @return static
     */
    public function ids( array $ids ): static
    {
        $this->params['ids'] = array_values( array_unique( array_map( 'intval', $ids ) ) );

        return $this;
    }

    /**
     * Only products matching `$term` through the configured search engine
     * (Scout); the `relevance` sort ranks them by the engine's order.
     *
     * @since 1.0.0
     *
     * @param  string  $term  Search term.
     *
     * @return static
     */
    public function search( string $term ): static
    {
        $this->params['search'] = mb_substr( trim( $term ), 0, 200 );

        return $this;
    }

    /**
     * The sort ({@see self::SORTS}); unknown sorts are ignored.
     *
     * @since 1.0.0
     *
     * @param  string  $sort  Sort.
     *
     * @return static
     */
    public function sort( string $sort ): static
    {
        if ( in_array( $sort, self::SORTS, true ) ) {
            $this->params['sort'] = $sort;
        }

        return $this;
    }

    /**
     * The parameters applied so far.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->params;
    }

    /**
     * The filtered query, sorted unless `$sorted` is false (for callers
     * that order it themselves), through `ap.ecommerce.product.listQuery`.
     *
     * @since 1.0.0
     *
     * @param  bool  $sorted  Apply the sort.
     *
     * @return Builder<Product>
     */
    public function builder( bool $sorted = true ): Builder
    {
        $query = $this->filtered();

        if ( $sorted ) {
            $this->applySort( $query );
        }

        $filtered = applyFilters( 'ap.ecommerce.product.listQuery', $query, $this->params );

        return $filtered instanceof Builder ? $filtered : $query;
    }

    /**
     * Counts for filter UIs, over the current filter set: the price range
     * (minor units of the query currency), attribute values, categories,
     * tags, and how many products are in stock.
     *
     * @since 1.0.0
     *
     * @return array{price: array{min: int|null, max: int|null, currency: string}, attributes: array<string, array<int, array{value: string, label: string, count: int}>>, categories: array<int, int>, tags: array<int, int>, stock: array{in_stock: int, total: int}}
     */
    public function facets(): array
    {
        $ids      = fn (): Builder => $this->filtered()->select( ( new Product() )->qualifyColumn( 'id' ) );
        $products = ( new Product() )->getTable();

        [ $priceSql, $priceBindings ] = $this->priceSql();

        $price = DB::query()
            ->fromSub( $this->filtered()->selectRaw( "{$priceSql} as catalog_price", $priceBindings ), 'priced' )
            ->selectRaw( 'MIN(catalog_price) as min_price, MAX(catalog_price) as max_price' )
            ->first();

        $attributes = ( new ProductAttribute() )->getTable();
        $values     = ( new ProductAttributeValue() )->getTable();

        $attributeCounts = [];

        DB::table( $attributes )
            ->join( $values, "{$values}.product_attribute_id", '=', "{$attributes}.id" )
            ->whereIn( "{$attributes}.product_id", $ids() )
            ->groupBy( "{$attributes}.key", "{$values}.value", "{$values}.label" )
            ->orderBy( "{$attributes}.key" )
            ->orderBy( "{$values}.value" )
            ->selectRaw( "{$attributes}.key as attribute_key, {$values}.value as value, {$values}.label as label, COUNT(DISTINCT {$attributes}.product_id) as products" )
            ->get()
            ->each( static function ( object $row ) use ( &$attributeCounts ): void {
                $attributeCounts[ (string) $row->attribute_key ][] = [ 'value' => (string) $row->value, 'label' => (string) $row->label, 'count' => (int) $row->products ];
            } );

        $categoryPivot = ( new ProductCategory() )->products()->getTable();
        $tagPivot      = ( new ProductTag() )->products()->getTable();

        $total = DB::query()->fromSub( $ids(), 'catalog_ids' )->count();

        $inStock = clone $this;
        $inStock->inStock();

        return [
            'price'      => [
                'min'      => null === ( $price->min_price ?? null ) ? null : (int) round( (float) $price->min_price ),
                'max'      => null === ( $price->max_price ?? null ) ? null : (int) round( (float) $price->max_price ),
                'currency' => $this->queryCurrency(),
            ],
            'attributes' => $attributeCounts,
            'categories' => DB::table( $categoryPivot )->whereIn( 'product_id', $ids() )->groupBy( 'product_category_id' )->selectRaw( 'product_category_id, COUNT(*) as products' )->pluck( 'products', 'product_category_id' )->map( static fn ( mixed $count ): int => (int) $count )->all(),
            'tags'       => DB::table( $tagPivot )->whereIn( 'product_id', $ids() )->groupBy( 'product_tag_id' )->selectRaw( 'product_tag_id, COUNT(*) as products' )->pluck( 'products', 'product_tag_id' )->map( static fn ( mixed $count ): int => (int) $count )->all(),
            'stock'      => [
                'in_stock' => DB::query()->fromSub( $inStock->filtered()->select( "{$products}.id" ), 'stocked_ids' )->count(),
                'total'    => $total,
            ],
        ];
    }

    /**
     * A storefront-visible product by slug.
     *
     * @since 1.0.0
     *
     * @param  string  $slug  Slug.
     *
     * @return Product|null
     */
    public function productBySlug( string $slug ): ?Product
    {
        return Product::query()->storefrontVisible()->where( 'slug', $slug )->first();
    }

    /**
     * The visible products matching every filter (unsorted).
     *
     * @since 1.0.0
     *
     * @return Builder<Product>
     */
    protected function filtered(): Builder
    {
        $model    = new Product();
        $products = $model->getTable();
        $query    = Product::query()->storefrontVisible();
        $params   = $this->params;

        if ( array_key_exists( 'category', $params ) ) {
            $query->whereHas( 'categories', static fn ( Builder $categories ) => $categories->whereIn( ( new ProductCategory() )->qualifyColumn( 'id' ), $params['category'] ?: [ 0 ] ) );
        }

        if ( array_key_exists( 'tag', $params ) ) {
            $query->whereHas( 'tags', static fn ( Builder $tags ) => $tags->whereKey( $params['tag'] ) );
        }

        foreach ( (array) ( $params['attributes'] ?? [] ) as $key => $values ) {
            $query->whereHas( 'productAttributes', static fn ( Builder $attribute ) => $attribute
                ->where( 'key', $key )
                ->whereHas( 'values', static fn ( Builder $value ) => $value->whereIn( 'value', $values ) ) );
        }

        if ( isset( $params['price'] ) ) {
            [ $min, $max ]       = $params['price'];
            [ $sql, $bindings ]  = $this->priceSql();

            if ( null !== $min ) {
                $query->whereRaw( "{$sql} >= ?", [ ...$bindings, $min ] );
            }

            if ( null !== $max ) {
                $query->whereRaw( "{$sql} <= ?", [ ...$bindings, $max ] );
            }
        }

        if ( $params['in_stock'] ?? false ) {
            $this->applyInStock( $query, $products );
        }

        if ( $params['on_sale'] ?? false ) {
            $this->applyOnSale( $query, $products );
        }

        if ( isset( $params['min_rating'] ) ) {
            $query->where( $model->qualifyColumn( 'avg_rating' ), '>=', $params['min_rating'] );
        }

        if ( $params['featured'] ?? false ) {
            $query->where( $model->qualifyColumn( 'is_featured' ), true );
        }

        if ( isset( $params['ids'] ) ) {
            $query->whereKey( $params['ids'] ?: [ 0 ] );
        }

        if ( isset( $params['search'] ) ) {
            $query->whereKey( $this->searchHits() ?: [ 0 ] );
        }

        return $query;
    }

    /**
     * Orders the query.
     *
     * @since 1.0.0
     *
     * @param  Builder<Product>  $query  Query.
     *
     * @return void
     */
    protected function applySort( Builder $query ): void
    {
        $model = new Product();
        $sort  = $this->params['sort'] ?? ( isset( $this->params['search'] ) ? 'relevance' : 'position' );

        switch ( $sort ) {
            case 'relevance':
                $hits = isset( $this->params['search'] ) ? $this->searchHits() : [];

                if ( [] !== $hits ) {
                    $cases = implode( ' ', array_fill( 0, count( $hits ), 'WHEN ? THEN ?' ) );
                    $pairs = [];

                    foreach ( $hits as $rank => $id ) {
                        $pairs[] = $id;
                        $pairs[] = $rank;
                    }

                    $query->orderByRaw( "CASE {$model->qualifyColumn( 'id' )} {$cases} ELSE " . count( $hits ) . ' END', $pairs );
                }

                $query->orderBy( $model->qualifyColumn( 'position' ) );

                break;

            case 'newest':
                $query->orderByRaw( 'COALESCE(' . $model->qualifyColumn( 'published_at' ) . ', ' . $model->qualifyColumn( 'created_at' ) . ') DESC' );

                break;

            case 'price':
            case '-price':
                [ $sql, $bindings ] = $this->priceSql();

                // Unpriced products go last either way.
                $query->orderByRaw( "CASE WHEN {$sql} IS NULL THEN 1 ELSE 0 END", $bindings )
                    ->orderByRaw( "{$sql} " . ( 'price' === $sort ? 'ASC' : 'DESC' ), $bindings );

                break;

            case 'popularity':
                $items  = ( new OrderItem() )->getTable();
                $orders = ( new Order() )->getTable();

                $query->orderByDesc(
                    OrderItem::query()
                        ->toBase()
                        ->join( $orders, "{$orders}.id", '=', "{$items}.order_id" )
                        ->whereColumn( "{$items}.product_id", $model->qualifyColumn( 'id' ) )
                        ->whereIn( "{$orders}.payment_status", Report::SALE_PAYMENT_STATUSES )
                        ->selectRaw( "COALESCE(SUM({$items}.quantity), 0)" ),
                );

                break;

            case 'rating':
                $query->orderByDesc( $model->qualifyColumn( 'avg_rating' ) )->orderByDesc( $model->qualifyColumn( 'reviews_count' ) );

                break;

            case 'name':
                $query->orderBy( $model->qualifyColumn( 'name' ) );

                break;

            default:
                $query->orderBy( $model->qualifyColumn( 'position' ) );
        }

        $query->orderBy( $model->qualifyColumn( 'id' ) );
    }

    /**
     * Restricts `$query` to products that can be bought now.
     *
     * @since 1.0.0
     *
     * @param  Builder<Product>  $query     Query.
     * @param  string            $products  Products table.
     *
     * @return void
     */
    protected function applyInStock( Builder $query, string $products ): void
    {
        $inventory = ( new InventoryItem() )->getTable();
        $variants  = ( new ProductVariant() )->getTable();
        $product   = ( new Product() )->getMorphClass();
        $variant   = ( new ProductVariant() )->getMorphClass();

        $rows = static fn ( $rows ) => $rows->from( $inventory )
            ->where( static fn ( $owner ) => $owner
                ->where( static fn ( $own ) => $own->where( "{$inventory}.stockable_type", $product )->whereColumn( "{$inventory}.stockable_id", "{$products}.id" ) )
                ->orWhere( static fn ( $child ) => $child->where( "{$inventory}.stockable_type", $variant )->whereIn( "{$inventory}.stockable_id", static fn ( $ids ) => $ids->from( $variants )->select( 'id' )->whereColumn( "{$variants}.product_id", "{$products}.id" ) ) ) );

        // In stock: nothing tracked at all, or some row that can sell.
        $query->where( static fn ( Builder $stock ) => $stock
            ->whereNotExists( static fn ( $tracked ) => $rows( $tracked->selectRaw( '1' ) )->where( "{$inventory}.track_inventory", true ) )
            ->orWhereExists( static fn ( $available ) => $rows( $available->selectRaw( '1' ) )->where( static fn ( $sellable ) => $sellable
                ->where( "{$inventory}.track_inventory", false )
                ->orWhere( "{$inventory}.allow_backorder", true )
                ->orWhereRaw( "{$inventory}.quantity_on_hand - {$inventory}.quantity_reserved > 0" ) ) ) );
    }

    /**
     * Restricts `$query` to products with a current sale price in the query
     * currency (or the base currency).
     *
     * @since 1.0.0
     *
     * @param  Builder<Product>  $query     Query.
     * @param  string            $products  Products table.
     *
     * @return void
     */
    protected function applyOnSale( Builder $query, string $products ): void
    {
        $prices   = ( new ProductPrice() )->getTable();
        $now      = Carbon::now();
        $currency = [ $this->queryCurrency(), $this->currencies->base() ];

        $query->whereExists( fn ( $sale ) => $this->priceOwner( $sale->selectRaw( '1' )->from( $prices ), $prices, $products )
            ->whereIn( "{$prices}.currency", array_unique( $currency ) )
            ->whereNotNull( "{$prices}.compare_at_amount" )
            ->whereColumn( "{$prices}.compare_at_amount", '>', "{$prices}.price_amount" )
            ->where( static fn ( $window ) => $window->whereNull( "{$prices}.starts_at" )->orWhere( "{$prices}.starts_at", '<=', $now ) )
            ->where( static fn ( $window ) => $window->whereNull( "{$prices}.ends_at" )->orWhere( "{$prices}.ends_at", '>=', $now ) ) );
    }

    /**
     * Narrows a price query to rows of the product or its variants.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Database\Query\Builder  $query     Price query.
     * @param  string                              $prices    Prices table.
     * @param  string                              $products  Products table.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function priceOwner( $query, string $prices, string $products )
    {
        $variants = ( new ProductVariant() )->getTable();
        $product  = ( new Product() )->getMorphClass();
        $variant  = ( new ProductVariant() )->getMorphClass();

        return $query->where( static fn ( $owner ) => $owner
            ->where( static fn ( $own ) => $own->where( "{$prices}.priceable_type", $product )->whereColumn( "{$prices}.priceable_id", "{$products}.id" ) )
            ->orWhere( static fn ( $child ) => $child->where( "{$prices}.priceable_type", $variant )->whereIn( "{$prices}.priceable_id", static fn ( $ids ) => $ids->from( $variants )->select( 'id' )->whereColumn( "{$variants}.product_id", "{$products}.id" ) ) ) );
    }

    /**
     * SQL (with bindings) for a product's lowest current price in the query
     * currency, falling back to its base-currency price at the current rate.
     *
     * @since 1.0.0
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function priceSql(): array
    {
        $prices   = ( new ProductPrice() )->getTable();
        $products = ( new Product() )->getTable();
        $variants = ( new ProductVariant() )->getTable();
        $now      = Carbon::now()->toDateTimeString();
        $currency = $this->queryCurrency();
        $base     = $this->currencies->base();

        $lowest = static fn ( string $code ): array => [
            "(SELECT MIN(pp.price_amount) FROM {$prices} pp WHERE pp.currency = ?"
                . ' AND (pp.starts_at IS NULL OR pp.starts_at <= ?) AND (pp.ends_at IS NULL OR pp.ends_at >= ?)'
                . " AND ((pp.priceable_type = ? AND pp.priceable_id = {$products}.id)"
                . " OR (pp.priceable_type = ? AND pp.priceable_id IN (SELECT pv.id FROM {$variants} pv WHERE pv.product_id = {$products}.id))))",
            [ $code, $now, $now, ( new Product() )->getMorphClass(), ( new ProductVariant() )->getMorphClass() ],
        ];

        [ $sql, $bindings ] = $lowest( $currency );

        if ( $currency === $base ) {
            return [ $sql, $bindings ];
        }

        $rate = $this->rateFromBase( $currency );

        if ( null === $rate ) {
            return [ $sql, $bindings ];
        }

        [ $baseSql, $baseBindings ] = $lowest( $base );

        return [ "COALESCE({$sql}, ({$baseSql} * ? / 100000000))", [ ...$bindings, ...$baseBindings, $rate ] ];
    }

    /**
     * The base → `$currency` rate (×1e8), or null when none is available.
     *
     * @since 1.0.0
     *
     * @param  string  $currency  Currency.
     *
     * @return int|null
     */
    protected function rateFromBase( string $currency ): ?int
    {
        try {
            $rate = $this->rates->active()->getRateE8( new Currency( $this->currencies->base() ), new Currency( $currency ) );
        } catch ( Throwable ) {
            return null;
        }

        return $rate > 0 ? $rate : null;
    }

    /**
     * The currency prices are read in.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function queryCurrency(): string
    {
        return (string) ( $this->params['currency'] ?? $this->currencies->base() );
    }

    /**
     * Product ids matching the search term, in the engine's order (cached
     * for this query).
     *
     * @since 1.0.0
     *
     * @return array<int, int>
     */
    protected function searchHits(): array
    {
        $term = (string) ( $this->params['search'] ?? '' );

        if ( '' === $term ) {
            return [];
        }

        return $this->hits[ $term ] ??= 'database' === (string) config( 'artisanpack.ecommerce.search.driver', 'database' )
            ? $this->databaseHits( $term )
            : Product::search( $term )
                ->where( 'status', 'active' )
                ->take( self::MAX_SEARCH_RESULTS )
                ->keys()
                ->map( static fn ( mixed $id ): int => (int) $id )
                ->values()
                ->all();
    }

    /**
     * Search hits from the database: the term (LIKE wildcards escaped)
     * anywhere in the name, SKU, barcode, slug, or descriptions; names that
     * start with it rank first, then names that contain it.
     *
     * @since 1.0.0
     *
     * @param  string  $term  Search term.
     *
     * @return array<int, int>
     */
    protected function databaseHits( string $term ): array
    {
        $escaped = str_replace( [ '!', '%', '_' ], [ '!!', '!%', '!_' ], mb_strtolower( $term ) );
        $model   = new Product();
        $name    = 'LOWER(' . $model->qualifyColumn( 'name' ) . ')';

        $query = Product::query()->storefrontVisible()->where( static function ( Builder $match ) use ( $escaped, $model ): void {
            foreach ( Product::DATABASE_SEARCH_COLUMNS as $column ) {
                if ( 'id' !== $column ) {
                    $match->orWhereRaw( 'LOWER(' . $model->qualifyColumn( $column ) . ") LIKE ? ESCAPE '!'", [ '%' . $escaped . '%' ] );
                }
            }
        } );

        return $query
            ->orderByRaw( "CASE WHEN {$name} LIKE ? ESCAPE '!' THEN 0 WHEN {$name} LIKE ? ESCAPE '!' THEN 1 ELSE 2 END", [ $escaped . '%', '%' . $escaped . '%' ] )
            ->orderBy( $model->qualifyColumn( 'id' ) )
            ->limit( self::MAX_SEARCH_RESULTS )
            ->pluck( $model->qualifyColumn( 'id' ) )
            ->map( static fn ( mixed $id): int => (int) $id )
            ->all();
    }
}
