<?php

/**
 * Product model.
 *
 * The base row for every product kind. Type-specific columns live on
 * satellite tables or in per-type meta rows; this table intentionally has
 * no nullable "type-specific" columns.
 *
 * Engine spec §3.1.
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
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Product Eloquent model.
 *
 * The `type` column resolves through the {@see ProductTypeRegistry} at
 * runtime via {@see self::productType()}. An unknown value returns a
 * {@see \ArtisanPackUI\Ecommerce\ProductTypes\MissingProductType} placeholder
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
 * @property int|null                                                                   $warehouse_id
 * @property array<string, mixed>                                                       $meta
 * @property \Illuminate\Support\Carbon|null                                            $published_at
 * @property \Illuminate\Database\Eloquent\Collection<int, ProductVariant>              $variants
 * @property \Illuminate\Database\Eloquent\Collection<int, ProductAttribute>            $productAttributes
 * @property \Illuminate\Database\Eloquent\Collection<int, ProductPrice>                $prices
 */
class Product extends Model
{
    use HasFactory;

    /**
     * Table name (packages register their own migrations).
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'products';

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
     * Variants belonging to this product.
     *
     * @since 1.0.0
     *
     * @return HasMany<ProductVariant, self>
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
     * @return HasMany<ProductAttribute, self>
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
     * @return MorphMany<ProductPrice, self>
     */
    public function prices(): MorphMany
    {
        return $this->morphMany( ProductPrice::class, 'priceable' );
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
