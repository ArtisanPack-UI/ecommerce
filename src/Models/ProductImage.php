<?php

/**
 * ProductImage model.
 *
 * One image in a product's ordered gallery (engine spec §3.10). It points at
 * a media-library item when that package is installed, or carries a plain
 * `image_url` when it is not.
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

use ArtisanPackUI\Ecommerce\Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product gallery image Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int         $id
 * @property int         $product_id
 * @property int|null    $media_id
 * @property string|null $image_url
 * @property string|null $alt_text
 * @property int         $position
 * @property Product     $product
 */
class ProductImage extends Model
{
    use HasFactory;

    /**
     * Table name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'product_images';

    /**
     * Mass-assignable attributes.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'media_id',
        'image_url',
        'alt_text',
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
        'position' => 0,
    ];

    /**
     * The product this image belongs to.
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
     * Native attribute casts.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'media_id'   => 'integer',
            'position'   => 'integer',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return ProductImageFactory
     */
    protected static function newFactory(): ProductImageFactory
    {
        return ProductImageFactory::new();
    }
}
