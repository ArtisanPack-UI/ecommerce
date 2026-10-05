<?php

/**
 * Coupon model.
 *
 * A customer-enterable code that unlocks a {@see Promotion}. Codes are
 * stored upper-cased and matched case-insensitively.
 *
 * Engine spec §3.23.
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

use ArtisanPackUI\Ecommerce\Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Coupon Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int         $id
 * @property int         $promotion_id
 * @property string      $code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Promotion   $promotion
 */
class Coupon extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_coupons';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'promotion_id',
        'code',
    ];

    /**
     * Normalizes a customer-entered code for storage and lookup.
     *
     * @since 1.0.0
     *
     * @param  string  $code  Raw code.
     *
     * @return string
     */
    public static function normalize( string $code ): string
    {
        return strtoupper( trim( $code ) );
    }

    /**
     * Finds a coupon by customer-entered code.
     *
     * @since 1.0.0
     *
     * @param  string  $code  Raw code.
     *
     * @return self|null
     */
    public static function findByCode( string $code ): ?self
    {
        $normalized = self::normalize( $code );

        if ( '' === $normalized ) {
            return null;
        }

        return self::query()->where( 'code', $normalized )->first();
    }

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo( Promotion::class );
    }

    /**
     * Stores codes upper-cased so lookups are case-insensitive on every
     * database driver.
     *
     * @since 1.0.0
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: static fn ( string $value ): string => self::normalize( $value ),
        );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'promotion_id' => 'integer',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return CouponFactory
     */
    protected static function newFactory(): CouponFactory
    {
        return CouponFactory::new();
    }
}
