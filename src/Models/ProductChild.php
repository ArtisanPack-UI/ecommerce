<?php

/**
 * ProductChild model.
 *
 * One member of a `grouped` or `bundled` product (engine spec §3.10a): the
 * child product, optionally one of its variants, how many of it the parent
 * contains, and its position in the list.
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

use ArtisanPackUI\Ecommerce\Database\Factories\ProductChildFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grouped / bundled child link Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                 $id
 * @property int                 $parent_product_id
 * @property int                 $child_product_id
 * @property int|null            $child_variant_id
 * @property int                 $quantity
 * @property int                 $position
 * @property Product             $parent
 * @property Product             $product
 * @property ProductVariant|null $variant
 */
class ProductChild extends Model
{
    use HasFactory;

    /**
     * Table name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_product_children';

    /**
     * Mass-assignable attributes.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_product_id',
        'child_product_id',
        'child_variant_id',
        'quantity',
        'position',
    ];

    /**
     * Attribute defaults.
     *
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 1,
        'position' => 0,
    ];

    /**
     * The grouped or bundled product.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Product, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo( Product::class, 'parent_product_id' );
    }

    /**
     * The member product.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo( Product::class, 'child_product_id' );
    }

    /**
     * The pinned variant of the member product, if any.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo( ProductVariant::class, 'child_variant_id' );
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
            'parent_product_id' => 'integer',
            'child_product_id'  => 'integer',
            'child_variant_id'  => 'integer',
            'quantity'          => 'integer',
            'position'          => 'integer',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return ProductChildFactory
     */
    protected static function newFactory(): ProductChildFactory
    {
        return ProductChildFactory::new();
    }
}
