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
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
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
    protected $table = 'ecommerce_product_prices';

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
     * The identity of a price row within its owner: currency plus schedule
     * window. Stored in `window_key`, which the unique index uses because
     * the nullable window columns can't enforce uniqueness themselves.
     *
     * @since 1.0.0
     *
     * @param  string                  $currency  Currency code.
     * @param  DateTimeInterface|null  $starts    Window start.
     * @param  DateTimeInterface|null  $ends      Window end.
     *
     * @return string
     */
    public static function windowKeyFor( string $currency, ?DateTimeInterface $starts, ?DateTimeInterface $ends ): string
    {
        return strtoupper( $currency ) . '|' . ( $starts?->getTimestamp() ?? '' ) . '|' . ( $ends?->getTimestamp() ?? '' );
    }

    /**
     * The Product or ProductVariant this row prices.
     *
     * @since 1.0.0
     *
     * @return MorphTo<Model, $this>
     */
    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope: rows whose schedule window covers `$at` (a row with no window
     * is always current). Mirrors the window test in
     * {@see \ArtisanPackUI\Ecommerce\Services\ProductPriceResolver}.
     *
     * @since 1.0.0
     *
     * @param  Builder<ProductPrice>        $query
     * @param  DateTimeInterface|null     $at     Reference time (defaults to now).
     *
     * @return Builder<ProductPrice>
     */
    public function scopeCurrentAt( Builder $query, ?DateTimeInterface $at = null ): Builder
    {
        $at ??= \Illuminate\Support\Carbon::now();

        return $query
            ->where( fn ( Builder $q ) => $q->whereNull( 'starts_at' )->orWhere( 'starts_at', '<=', $at ) )
            ->where( fn ( Builder $q ) => $q->whereNull( 'ends_at' )->orWhere( 'ends_at', '>=', $at ) );
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
     * Keeps `window_key` in step with the currency and window.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::saving( static function ( ProductPrice $price ): void {
            $price->window_key = static::windowKeyFor( (string) $price->currency, $price->starts_at, $price->ends_at );
        } );
    }

    /**
     * @return ProductPriceFactory
     */
    protected static function newFactory(): ProductPriceFactory
    {
        return ProductPriceFactory::new();
    }
}
