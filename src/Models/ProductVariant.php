<?php

/**
 * ProductVariant model.
 *
 * Engine spec §3.3.
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

use ArtisanPackUI\Ecommerce\Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * ProductVariant Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                             $id
 * @property int                             $product_id
 * @property string|null                     $sku
 * @property string|null                     $barcode
 * @property string|null                     $name
 * @property int|null                        $image_media_id
 * @property float|null                      $weight
 * @property string|null                     $weight_unit
 * @property float|null                      $length
 * @property float|null                      $width
 * @property float|null                      $height
 * @property string|null                     $dim_unit
 * @property int                             $position
 * @property array<string, mixed>            $meta
 */
class ProductVariant extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'product_variants';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'name',
        'image_media_id',
        'weight',
        'weight_unit',
        'length',
        'width',
        'height',
        'dim_unit',
        'position',
        'meta',
    ];

    /**
     * Parent product.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo( Product::class );
    }

    /**
     * Per-currency prices for this variant.
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
     * Attribute-value assignments that compose this variant.
     *
     * @since 1.0.0
     *
     * @return HasMany<ProductVariantOptionValue, $this>
     */
    public function optionValues(): HasMany
    {
        return $this->hasMany( ProductVariantOptionValue::class );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight'   => 'float',
            'length'   => 'float',
            'width'    => 'float',
            'height'   => 'float',
            'position' => 'integer',
            'meta'     => 'array',
        ];
    }

    /**
     * @return ProductVariantFactory
     */
    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }
}
