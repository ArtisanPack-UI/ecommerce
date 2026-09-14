<?php

/**
 * ProductVariantOptionValue model.
 *
 * Pivot: which attribute values compose which variant. Engine spec §3.6.
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

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductVariantOptionValue Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int $product_variant_id
 * @property int $product_attribute_id
 * @property int $product_attribute_value_id
 */
class ProductVariantOptionValue extends Model
{
    /**
     * Rows carry no timestamps (spec §3.6).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var string
     */
    protected $table = 'product_variant_option_values';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'product_variant_id',
        'product_attribute_id',
        'product_attribute_value_id',
    ];

    /**
     * @return BelongsTo<ProductVariant, self>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo( ProductVariant::class, 'product_variant_id' );
    }

    /**
     * @return BelongsTo<ProductAttribute, self>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo( ProductAttribute::class, 'product_attribute_id' );
    }

    /**
     * @return BelongsTo<ProductAttributeValue, self>
     */
    public function value(): BelongsTo
    {
        return $this->belongsTo( ProductAttributeValue::class, 'product_attribute_value_id' );
    }
}
