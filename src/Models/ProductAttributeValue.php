<?php

/**
 * ProductAttributeValue model.
 *
 * Engine spec §3.5.
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

use ArtisanPackUI\Ecommerce\Database\Factories\ProductAttributeValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductAttributeValue Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int         $id
 * @property int         $product_attribute_id
 * @property string      $value
 * @property string      $label
 * @property string|null $swatch
 * @property int         $position
 */
class ProductAttributeValue extends Model
{
    use HasFactory;

    /**
     * These rows do not carry timestamps (spec §3.5).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var string
     */
    protected $table = 'product_attribute_values';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'product_attribute_id',
        'value',
        'label',
        'swatch',
        'position',
    ];

    /**
     * @return BelongsTo<ProductAttribute, self>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo( ProductAttribute::class, 'product_attribute_id' );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return ProductAttributeValueFactory
     */
    protected static function newFactory(): ProductAttributeValueFactory
    {
        return ProductAttributeValueFactory::new();
    }
}
