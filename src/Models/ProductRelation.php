<?php

/**
 * ProductRelation model.
 *
 * A hand-picked link from one product to another (#182): an upsell, a
 * cross-sell, or a related product.
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
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int      $id
 * @property int      $product_id
 * @property int      $related_product_id
 * @property string   $type
 * @property int      $position
 * @property Product  $product
 * @property Product  $related
 */
class ProductRelation extends Model
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const UPSELL = 'upsell';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const CROSS_SELL = 'cross_sell';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const RELATED = 'related';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const TYPES = [ self::UPSELL, self::CROSS_SELL, self::RELATED ];

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_product_relations';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'related_product_id',
        'type',
        'position',
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo( Product::class, 'product_id' );
    }

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<Product, $this>
     */
    public function related(): BelongsTo
    {
        return $this->belongsTo( Product::class, 'related_product_id' );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id'         => 'integer',
            'related_product_id' => 'integer',
            'position'           => 'integer',
        ];
    }
}
