<?php

/**
 * ProductCategory model.
 *
 * A node in the category tree (engine spec §3.7). Products attach through
 * the `product_category_product` pivot.
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

use ArtisanPackUI\Ecommerce\Catalog\CategoryTree;
use ArtisanPackUI\Ecommerce\Database\Factories\ProductCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Product category Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                            $id
 * @property int|null                                                       $parent_id
 * @property string                                                         $name
 * @property string                                                         $slug
 * @property string|null                                                    $description
 * @property int|null                                                       $image_media_id
 * @property string|null                                                    $icon
 * @property int                                                            $position
 * @property ProductCategory|null                                           $parent
 * @property \Illuminate\Database\Eloquent\Collection<int, ProductCategory> $children
 * @property \Illuminate\Database\Eloquent\Collection<int, Product>         $products
 */
class ProductCategory extends Model
{
    use HasFactory;

    /**
     * Table name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_product_categories';

    /**
     * Mass-assignable attributes.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'image_media_id',
        'icon',
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
     * The parent category, if any.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<ProductCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo( self::class, 'parent_id' );
    }

    /**
     * Direct child categories, in position order.
     *
     * @since 1.0.0
     *
     * @return HasMany<ProductCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany( self::class, 'parent_id' )->orderBy( 'position' )->orderBy( 'id' );
    }

    /**
     * Products in this category.
     *
     * @since 1.0.0
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany( Product::class, 'ecommerce_product_category_product', 'product_category_id', 'product_id' );
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
            'parent_id'      => 'integer',
            'image_media_id' => 'integer',
            'position'       => 'integer',
        ];
    }

    /**
     * Rebuilds the cached category tree whenever a category changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::saved( static fn () => CategoryTree::flush() );
        static::deleted( static fn () => CategoryTree::flush() );
    }

    /**
     * @since 1.0.0
     *
     * @return ProductCategoryFactory
     */
    protected static function newFactory(): ProductCategoryFactory
    {
        return ProductCategoryFactory::new();
    }
}
