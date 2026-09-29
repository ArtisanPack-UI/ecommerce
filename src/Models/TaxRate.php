<?php

/**
 * TaxRate model.
 *
 * One jurisdictional rate for a tax class. `rate_ubps` is an integer in
 * micro-basis-points (see {@see \ArtisanPackUI\Ecommerce\Support\TaxRateMath});
 * `is_compound` rates are levied on the base plus every non-compound tax
 * (e.g. Québec QST over GST).
 *
 * Engine spec §3.24.
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

use ArtisanPackUI\Ecommerce\Database\Factories\TaxRateFactory;
use ArtisanPackUI\Ecommerce\Support\TaxRateMath;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TaxRate Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int         $id
 * @property string      $tax_class_key
 * @property string      $country_code
 * @property string|null $region_code
 * @property string|null $postal_pattern
 * @property int         $rate_ubps
 * @property bool        $is_compound
 * @property int         $priority
 * @property string      $label
 * @property bool        $is_shipping_taxable
 * @property bool        $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TaxRate extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'tax_rates';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tax_class_key',
        'country_code',
        'region_code',
        'postal_pattern',
        'rate_ubps',
        'is_compound',
        'priority',
        'label',
        'is_shipping_taxable',
        'is_active',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_compound'         => false,
        'priority'            => 0,
        'is_shipping_taxable' => false,
        'is_active'           => true,
    ];

    /**
     * Tax class this rate belongs to.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<TaxClass, $this>
     */
    public function taxClass(): BelongsTo
    {
        return $this->belongsTo( TaxClass::class, 'tax_class_key', 'key' );
    }

    /**
     * Scope: active rates only.
     *
     * @since 1.0.0
     *
     * @param  Builder<TaxRate>  $query
     *
     * @return Builder<TaxRate>
     */
    public function scopeActive( Builder $query ): Builder
    {
        return $query->where( 'is_active', true );
    }

    /**
     * Whether this rate applies to `$address`. Country must match; a
     * non-null region must match the address region code; a non-null
     * postal pattern must match the postal code (see {@see self::postalMatches()}).
     *
     * @since 1.0.0
     *
     * @param  Address  $address  Destination address.
     *
     * @return bool
     */
    public function matches( Address $address ): bool
    {
        if ( strtoupper( $this->country_code ) !== strtoupper( $address->countryCode ) ) {
            return false;
        }

        if ( null !== $this->region_code && '' !== $this->region_code ) {
            if ( null === $address->regionCode
                || self::normalizeRegion( $this->region_code, $this->country_code ) !== self::normalizeRegion( $address->regionCode, $address->countryCode ) ) {
                return false;
            }
        }

        if ( null !== $this->postal_pattern && '' !== $this->postal_pattern ) {
            return self::postalMatches( $this->postal_pattern, $address->postalCode );
        }

        return true;
    }

    /**
     * Specificity rank used to pick one rate per priority level: postal
     * beats region beats country-only.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function specificity(): int
    {
        return ( empty( $this->postal_pattern ) ? 0 : 2 ) + ( empty( $this->region_code ) ? 0 : 1 );
    }

    /**
     * Rate as a human percentage string (`"8.375"`).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function percent(): string
    {
        return TaxRateMath::toPercent( (int) $this->rate_ubps );
    }

    /**
     * Normalizes a region code for comparison: upper-cased, with an ISO
     * 3166-2 country prefix (`US-IL`) stripped so `IL` and `US-IL` match.
     * Shared with {@see ShippingZone::matches()} so tax and shipping agree
     * on which addresses a region covers.
     *
     * @since 1.0.0
     *
     * @param  string  $region       Region code.
     * @param  string  $countryCode  ISO 3166-1 alpha-2 country code.
     *
     * @return string
     */
    public static function normalizeRegion( string $region, string $countryCode ): string
    {
        $region = strtoupper( trim( $region ) );
        $prefix = strtoupper( $countryCode ) . '-';

        return str_starts_with( $region, $prefix ) ? substr( $region, strlen( $prefix ) ) : $region;
    }

    /**
     * Matches a postal code against a comma-separated list of patterns.
     * Each pattern is an exact code, a `*` glob (`606*`), or an inclusive
     * numeric range (`60601...60699`). Comparison ignores case and spaces.
     *
     * @since 1.0.0
     *
     * @param  string       $patterns    Comma-separated patterns.
     * @param  string|null  $postalCode  Postal code to test.
     *
     * @return bool
     */
    public static function postalMatches( string $patterns, ?string $postalCode ): bool
    {
        if ( null === $postalCode || '' === trim( $postalCode ) ) {
            return false;
        }

        $code = strtoupper( str_replace( ' ', '', $postalCode ) );

        foreach ( explode( ',', $patterns ) as $pattern ) {
            $pattern = strtoupper( str_replace( ' ', '', $pattern ) );

            if ( '' === $pattern ) {
                continue;
            }

            if ( str_contains( $pattern, '...' ) ) {
                [ $low, $high ] = explode( '...', $pattern, 2 );

                if ( ctype_digit( $low ) && ctype_digit( $high ) && ctype_digit( $code )
                    && (int) $code >= (int) $low && (int) $code <= (int) $high ) {
                    return true;
                }

                continue;
            }

            if ( str_contains( $pattern, '*' ) ) {
                $regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';

                if ( 1 === preg_match( $regex, $code ) ) {
                    return true;
                }

                continue;
            }

            if ( $pattern === $code ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_ubps'           => 'integer',
            'is_compound'         => 'boolean',
            'priority'            => 'integer',
            'is_shipping_taxable' => 'boolean',
            'is_active'           => 'boolean',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return TaxRateFactory
     */
    protected static function newFactory(): TaxRateFactory
    {
        return TaxRateFactory::new();
    }
}
