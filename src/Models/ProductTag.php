<?php

/**
 * ProductTag model.
 *
 * A flat product label (engine spec §3.9). Products attach through the
 * `product_tag_product` pivot.
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

use ArtisanPackUI\Ecommerce\Database\Factories\ProductTagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Product tag Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                    $id
 * @property string                                                 $name
 * @property string                                                 $slug
 * @property \Illuminate\Database\Eloquent\Collection<int, Product> $products
 */
class ProductTag extends Model
{
    use HasFactory;

    /**
     * Table name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_product_tags';

    /**
     * Mass-assignable attributes.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * Products carrying this tag.
     *
     * @since 1.0.0
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany( Product::class, 'ecommerce_product_tag_product', 'product_tag_id', 'product_id' );
    }

    /**
     * @since 1.0.0
     *
     * @return ProductTagFactory
     */
    protected static function newFactory(): ProductTagFactory
    {
        return ProductTagFactory::new();
    }
}
