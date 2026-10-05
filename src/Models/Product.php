<?php

/**
 * Product model.
 *
 * The base row for every product kind. Type-specific columns live on
 * satellite tables or in per-type meta rows; this table intentionally has
 * no nullable "type-specific" columns.
 *
 * Engine spec §3.1. Searchable through Laravel Scout (parent plan §4.1):
 * the engine defaults to Scout's `database` driver so `Product::search()`
 * works out of the box; point `artisanpack.ecommerce.search.driver` at
 * `meilisearch` / `typesense` / `algolia` to use a dedicated index.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Models;

use ArtisanPackUI\Ecommerce\Contracts\ProductType;
use ArtisanPackUI\Ecommerce\Database\Factories\ProductFactory;
use ArtisanPackUI\Ecommerce\Inventory\StockStatus;
use ArtisanPackUI\Ecommerce\ProductTypes\MissingProductType;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use ArtisanPackUI\Ecommerce\Services\ProductPriceResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\DatabaseEngine;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Searchable;

/**
 * Product Eloquent model.
 *
 * The `type` column resolves through the {@see ProductTypeRegistry} at
 * runtime via {@see self::productType()}. An unknown value returns a
 * {@see MissingProductType} placeholder
 * so orphaned rows never fatal the storefront.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                                        $id
 * @property string                                                                     $type
 * @property string                                                                     $name
 * @property string                                                                     $slug
 * @property string|null                                                                $sku
 * @property string|null                                                                $barcode
 * @property string|null                                                                $description
 * @property string|null                                                                $short_description
 * @property string                                                                     $status
 * @property int|null                                                                   $featured_image_media_id
 * @property bool                                                                       $is_taxable
 * @property string|null                                                                $tax_class_key
 * @property float|null                                                                 $weight
 * @property string|null                                                                $weight_unit
 * @property float|null                                                                 $length
 * @property float|null                                                                 $width
 * @property float|null                                                                 $height
 * @property string|null                                                                $dim_unit
 * @property float                                                                      $avg_rating
 * @property int                                                                        $reviews_count
 * @property bool                                                                       $is_featured
 * @property int                                                                        $position
 * @property int|null                                                                   $warehouse_id
 * @property array<string, mixed>                                                       $meta
 * @property Carbon|null                                            $published_at
 * @property \Illuminate\Database\Eloquent\Collection<int, ProductVariant>              $variants
 * @property \Illuminate\Database\Eloquent\Collection<int, ProductAttribute>            $productAttributes
 * @property \Illuminate\Database\Eloquent\Collection<int, ProductPrice>                $prices
 * @property \Illuminate\Database\Eloquent\Collection<int, ProductReview>               $reviews
 * @property \Illuminate\Database\Eloquent\Collection<int, DigitalFile>                 $digitalFiles
 * @property \Illuminate\Database\Eloquent\Collection<int, ProductCategory>             $categories
 * @property \Illuminate\Database\Eloquent\Collection<int, ProductTag>                  $tags
 * @property \Illuminate\Database\Eloquent\Collection<int, ProductImage>                $images
 * @property \Illuminate\Database\Eloquent\Collection<int, ProductChild>                $children
 * @property \Illuminate\Database\Eloquent\Collection<int, InventoryItem>               $inventoryItems
 */
class Product extends Model
{
    use HasFactory;
    use Searchable;

    /**
     * Columns the Scout `database` driver searches. That driver turns
     * every `toSearchableArray()` key into a `LIKE` on the column of the
     * same name, so only real text columns may appear when it is active.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const DATABASE_SEARCH_COLUMNS = [
        'id',
        'name',
        'slug',
        'sku',
        'barcode',
        'short_description',
        'description',
    ];

    /**
     * Table name (packages register their own migrations).
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_products';

    /**
     * Mass-assignable attributes.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'type',
        'name',
        'slug',
        'sku',
        'barcode',
        'description',
        'short_description',
        'status',
        'featured_image_media_id',
        'is_taxable',
        'tax_class_key',
        'weight',
        'weight_unit',
        'length',
        'width',
        'height',
        'dim_unit',
        'avg_rating',
        'reviews_count',
        'is_featured',
        'position',
        'warehouse_id',
        'meta',
        'published_at',
    ];

    /**
     * Attribute defaults.
     *
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status'        => 'draft',
        'is_taxable'    => true,
        'avg_rating'    => 0.0,
        'reviews_count' => 0,
        'is_featured'   => false,
        'position'      => 0,
    ];

    /**
     * Resolves the {@see ProductType} implementation for this row.
     *
     * @since 1.0.0
     *
     * @return ProductType
     */
    public function productType(): ProductType
    {
        return app( ProductTypeRegistry::class )->get( $this->type );
    }

    /**
     * Whether this product's `type` is not registered — typically because
     * the satellite providing it was uninstalled (parent plan §16.6). The
     * product stays queryable but is read-only and cannot be sold.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function typeIsMissing(): bool
    {
        return $this->productType() instanceof MissingProductType;
    }

    /**
     * Whether admin surfaces may edit this product.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isEditable(): bool
    {
        return ! $this->typeIsMissing();
    }

    /**
     * The read-only warning for a product whose type is missing, or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function typeWarning(): ?string
    {
        $type = $this->productType();

        return $type instanceof MissingProductType ? $type->readOnlyWarning() : null;
    }

    /**
     * Variants belonging to this product.
     *
     * @since 1.0.0
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany( ProductVariant::class );
    }

    /**
     * Attribute definitions for this product (size, color, …).
     *
     * Named `productAttributes()` rather than `attributes()` because
     * Eloquent's internal `$attributes` array property collides with a
     * relation of that name — `$product->attributes` would return the raw
     * column map, not the relation.
     *
     * @since 1.0.0
     *
     * @return HasMany<ProductAttribute, $this>
     */
    public function productAttributes(): HasMany
    {
        return $this->hasMany( ProductAttribute::class );
    }

    /**
     * Per-currency prices for the product row itself (variant prices live on
     * {@see ProductVariant::prices()}). Polymorphic via `product_prices`.
     *
     * @since 1.0.0
     *
     * @return MorphMany<ProductPrice, $this>
     */
    public function prices(): MorphMany
    {
        return $this->morphMany( ProductPrice::class, 'priceable' );
    }

    /**
     * Every review of the product, in any moderation status. Use
     * `->approved()` for the storefront-visible ones.
     *
     * @since 1.0.0
     *
     * @return HasMany<ProductReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany( ProductReview::class );
    }

    /**
     * Deliverable files for the product (and its variants).
     *
     * @since 1.0.0
     *
     * @return HasMany<DigitalFile, $this>
     */
    public function digitalFiles(): HasMany
    {
        return $this->hasMany( DigitalFile::class );
    }

    /**
     * Categories the product is filed under.
     *
     * @since 1.0.0
     *
     * @return BelongsToMany<ProductCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany( ProductCategory::class, 'ecommerce_product_category_product', 'product_id', 'product_category_id' );
    }

    /**
     * Tags on the product.
     *
     * @since 1.0.0
     *
     * @return BelongsToMany<ProductTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany( ProductTag::class, 'ecommerce_product_tag_product', 'product_id', 'product_tag_id' );
    }

    /**
     * The ordered gallery (the featured image lives on the product row).
     *
     * @since 1.0.0
     *
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany( ProductImage::class )->orderBy( 'position' )->orderBy( 'id' );
    }

    /**
     * Members of a `grouped` or `bundled` product, in order.
     *
     * @since 1.0.0
     *
     * @return HasMany<ProductChild, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany( ProductChild::class, 'parent_product_id' )->orderBy( 'position' )->orderBy( 'id' );
    }

    /**
     * Stock rows for the product itself (variant stock lives on
     * {@see ProductVariant::inventoryItems()}).
     *
     * @since 1.0.0
     *
     * @return MorphMany<InventoryItem, $this>
     */
    public function inventoryItems(): MorphMany
    {
        return $this->morphMany( InventoryItem::class, 'stockable' );
    }

    /**
     * Products a shopper may see: `active` and already published (or with
     * no publish date).
     *
     * @since 1.0.0
     *
     * @param  Builder<Product>  $query  Query.
     *
     * @return void
     */
    public function scopeStorefrontVisible( Builder $query ): void
    {
        $query->where( $this->qualifyColumn( 'status' ), 'active' )
            ->where( fn ( Builder $q ) => $q->whereNull( $this->qualifyColumn( 'published_at' ) )->orWhere( $this->qualifyColumn( 'published_at' ), '<=', Carbon::now() ) );
    }

    /**
     * The document indexed for search.
     *
     * Dedicated engines get the text fields plus what storefronts facet on
     * (#176): category and tag ids and slugs, attribute values by key, the
     * current price per currency, and whether it's in stock. It runs
     * through `ap.ecommerce.product.searchableData` (engine spec §6.9) so
     * satellites can add more. Under the `database` driver the document is
     * cut back to {@see self::DATABASE_SEARCH_COLUMNS}, because that driver
     * can only search real columns (filters and facets then come from the
     * catalog query).
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $database = $this->searchableUsing() instanceof DatabaseEngine;

        $data = (array) applyFilters( 'ap.ecommerce.product.searchableData', [
            'id'                => $this->getKey(),
            'name'              => $this->name,
            'slug'              => $this->slug,
            'sku'               => $this->sku,
            'barcode'           => $this->barcode,
            'short_description' => $this->short_description,
            'description'       => $this->description,
            'type'              => $this->type,
            'status'            => $this->status,
            'is_featured'       => (bool) $this->is_featured,
            'avg_rating'        => $this->avg_rating,
            'reviews_count'     => $this->reviews_count,
            'published_at'      => $this->published_at?->getTimestamp(),
        ] + ( $database ? [] : $this->searchFacetFields() ), $this );

        if ( $database ) {
            return array_intersect_key( $data, array_flip( self::DATABASE_SEARCH_COLUMNS ) );
        }

        return $data;
    }

    /**
     * The Scout engine for products: `artisanpack.ecommerce.search.driver`
     * when set (default `database`), otherwise the app's `scout.driver`.
     *
     * @since 1.0.0
     *
     * @return Engine
     */
    public function searchableUsing(): Engine
    {
        $driver = config( 'artisanpack.ecommerce.search.driver' );

        return app( EngineManager::class )->engine( is_string( $driver ) && '' !== $driver ? $driver : null );
    }

    /**
     * Index name, namespaced so it can't collide with a host app's own
     * `products` index on a shared search cluster.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function searchableAs(): string
    {
        return config( 'scout.prefix', '' ) . (string) config( 'artisanpack.ecommerce.search.index', 'ecommerce_products' );
    }

    /**
     * Only `active` products are indexed (while the `scout` feature is on),
     * so dedicated engines page over sellable products instead of returning
     * short pages after drafts are filtered out. Scout removes a product
     * from the index when it stops being active.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function shouldBeSearchable(): bool
    {
        return (bool) config( 'artisanpack.ecommerce.features.scout', true ) && 'active' === $this->status;
    }

    /**
     * Eager-loads what {@see self::toSearchableArray()} reads when Scout
     * imports products in bulk.
     *
     * @since 1.0.0
     *
     * @param  Builder<Product>  $query  Import query.
     *
     * @return Builder<Product>
     */
    protected function makeAllSearchableUsing( Builder $query ): Builder
    {
        return $query->with( [ 'categories', 'tags', 'productAttributes.values', 'prices' ] );
    }

    /**
     * The facetable fields of the search document.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    protected function searchFacetFields(): array
    {
        $prices = [];

        foreach ( $this->prices->whereNull( 'starts_at' )->whereNull( 'ends_at' ) as $price ) {
            $prices[ strtoupper( (string) $price->currency ) ] = (int) $price->price_amount;
        }

        foreach ( array_keys( $prices ) as $currency ) {
            $current = app( ProductPriceResolver::class )->resolve( $this, $currency );

            if ( null !== $current ) {
                $prices[ $currency ] = (int) $current->getAmount();
            }
        }

        $attributes = [];

        foreach ( $this->productAttributes as $attribute ) {
            $attributes[ (string) $attribute->key ] = $attribute->values->pluck( 'value' )->map( static fn ( $value ): string => (string) $value )->values()->all();
        }

        return [
            'category_ids'   => $this->categories->pluck( 'id' )->map( static fn ( $id ): int => (int) $id )->values()->all(),
            'category_slugs' => $this->categories->pluck( 'slug' )->values()->all(),
            'tag_ids'        => $this->tags->pluck( 'id' )->map( static fn ( $id ): int => (int) $id )->values()->all(),
            'tag_slugs'      => $this->tags->pluck( 'slug' )->values()->all(),
            'attributes'     => $attributes,
            'prices'         => $prices,
            'in_stock'       => StockStatus::for( $this )->purchasable(),
        ];
    }

    /**
     * Fires the product lifecycle hooks (engine spec §6.9) from the model,
     * so every write path — services, imports, raw Eloquent — fires them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::saving( static function ( Product $product ): void {
            doAction( 'ap.ecommerce.product.saving', $product );
        } );

        // The post-write hooks wait for the surrounding transaction to commit
        // (they run at once outside one), so listeners see the product with
        // its prices and links, and never see a write that rolled back.
        static::created( static function ( Product $product ): void {
            if ( 'active' === $product->status ) {
                DB::afterCommit( static fn () => doAction( 'ap.ecommerce.product.published', $product ) );
            }
        } );

        static::updated( static function ( Product $product ): void {
            if ( ! $product->wasChanged( 'status' ) ) {
                return;
            }

            // The original isn't synced until after `saved`, so it still holds the old status here.
            if ( 'active' === $product->status ) {
                DB::afterCommit( static fn () => doAction( 'ap.ecommerce.product.published', $product ) );
            } elseif ( 'active' === $product->getOriginal( 'status' ) ) {
                DB::afterCommit( static fn () => doAction( 'ap.ecommerce.product.unpublished', $product ) );
            }
        } );

        static::saved( static function ( Product $product ): void {
            DB::afterCommit( static fn () => doAction( 'ap.ecommerce.product.saved', $product ) );
        } );

        static::deleted( static function ( Product $product ): void {
            DB::afterCommit( static fn () => doAction( 'ap.ecommerce.product.deleted', $product ) );
        } );
    }

    /**
     * Native attribute casts.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_taxable'    => 'boolean',
            'weight'        => 'float',
            'length'        => 'float',
            'width'         => 'float',
            'height'        => 'float',
            'avg_rating'    => 'float',
            'reviews_count' => 'integer',
            'is_featured'   => 'boolean',
            'position'      => 'integer',
            'meta'          => 'array',
            'published_at'  => 'datetime',
        ];
    }

    /**
     * Wires the model to its factory. Package models cannot rely on
     * Laravel's `App\Models` → `Database\Factories\` convention.
     *
     * @since 1.0.0
     *
     * @return ProductFactory
     */
    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
