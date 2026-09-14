<?php

/**
 * ProductPrice model.
 *
 * Per-currency prices for products AND variants (polymorphic via
 * `priceable_type` / `priceable_id`). Engine spec §3.2.
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

use ArtisanPackUI\Ecommerce\Casts\MoneyCast;
use ArtisanPackUI\Ecommerce\Database\Factories\ProductPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ProductPrice Eloquent model.
 *
 * The paired `{amount, currency}` columns for `price`, `compare_at`, and
 * `cost` all cast to {@see \Money\Money} via {@see MoneyCast}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                             $id
 * @property string                          $priceable_type
 * @property int                             $priceable_id
 * @property string                          $currency
 * @property \Money\Money|null               $price
 * @property \Money\Money|null               $compare_at
 * @property \Money\Money|null               $cost
 * @property \Illuminate\Support\Carbon|null $starts_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 */
class ProductPrice extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'product_prices';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'priceable_type',
        'priceable_id',
        'currency',
        'price_amount',
        'compare_at_amount',
        'cost_amount',
        'starts_at',
        'ends_at',
    ];

    /**
     * The Product or ProductVariant this row prices.
     *
     * @since 1.0.0
     *
     * @return MorphTo<Model, self>
     */
    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Money columns are stored as `{prefix}_amount` + shared `currency`.
     * MoneyCast pairs each amount with the shared `currency` column.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price'      => MoneyCast::class . ':price_amount,currency',
            'compare_at' => MoneyCast::class . ':compare_at_amount,currency',
            'cost'       => MoneyCast::class . ':cost_amount,currency',
            'starts_at'  => 'datetime',
            'ends_at'    => 'datetime',
        ];
    }

    /**
     * @return ProductPriceFactory
     */
    protected static function newFactory(): ProductPriceFactory
    {
        return ProductPriceFactory::new();
    }
}
