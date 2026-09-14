<?php

/**
 * ProductAttribute model.
 *
 * Engine spec §3.4.
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

use ArtisanPackUI\Ecommerce\Database\Factories\ProductAttributeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ProductAttribute Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int    $id
 * @property int    $product_id
 * @property string $key
 * @property string $label
 * @property int    $position
 * @property bool   $is_variation
 */
class ProductAttribute extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'product_attributes';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'key',
        'label',
        'position',
        'is_variation',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'position'     => 0,
        'is_variation' => true,
    ];

    /**
     * @return BelongsTo<Product, self>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo( Product::class );
    }

    /**
     * @return HasMany<ProductAttributeValue, self>
     */
    public function values(): HasMany
    {
        return $this->hasMany( ProductAttributeValue::class );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position'     => 'integer',
            'is_variation' => 'boolean',
        ];
    }

    /**
     * @return ProductAttributeFactory
     */
    protected static function newFactory(): ProductAttributeFactory
    {
        return ProductAttributeFactory::new();
    }
}
