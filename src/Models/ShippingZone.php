<?php

/**
 * ShippingZone model.
 *
 * A geographic region (countries, optionally narrowed to regions and
 * postal patterns) that owns an ordered set of {@see ShippingMethod} rows.
 * A destination is served by the first active matching zone in
 * `priority` order (lower first, then id).
 *
 * Engine spec §3.25.
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

use ArtisanPackUI\Ecommerce\Database\Factories\ShippingZoneFactory;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * ShippingZone Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                     $id
 * @property string                  $name
 * @property array<int, string>      $country_codes
 * @property array<int, string>|null $region_codes
 * @property array<int, string>|null $postal_patterns
 * @property int                     $priority
 * @property bool                    $is_active
 * @property Carbon|null             $created_at
 * @property Carbon|null             $updated_at
 */
class ShippingZone extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_shipping_zones';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'country_codes',
        'region_codes',
        'postal_patterns',
        'priority',
        'is_active',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority'  => 0,
        'is_active' => true,
    ];

    /**
     * Methods offered in this zone, in display order.
     *
     * @since 1.0.0
     *
     * @return HasMany<ShippingMethod, $this>
     */
    public function methods(): HasMany
    {
        return $this->hasMany( ShippingMethod::class, 'zone_id' )->orderBy( 'position' )->orderBy( 'id' );
    }

    /**
     * Scope: active zones in match order.
     *
     * @since 1.0.0
     *
     * @param  Builder<ShippingZone>  $query
     *
     * @return Builder<ShippingZone>
     */
    public function scopeActiveInMatchOrder( Builder $query ): Builder
    {
        return $query->where( 'is_active', true )->orderBy( 'priority' )->orderBy( 'id' );
    }

    /**
     * Whether `$address` falls inside this zone.
     *
     * @since 1.0.0
     *
     * @param  Address  $address  Destination address.
     *
     * @return bool
     */
    public function matches( Address $address ): bool
    {
        $countries = array_map( 'strtoupper', (array) $this->country_codes );

        if ( ! in_array( strtoupper( $address->countryCode ), $countries, true ) ) {
            return false;
        }

        $regions = array_filter( (array) ( $this->region_codes ?? [] ) );

        if ( [] !== $regions ) {
            if ( null === $address->regionCode ) {
                return false;
            }

            $wanted = TaxRate::normalizeRegion( $address->regionCode, $address->countryCode );
            $found  = false;

            foreach ( $regions as $region ) {
                if ( TaxRate::normalizeRegion( (string) $region, $address->countryCode ) === $wanted ) {
                    $found = true;

                    break;
                }
            }

            if ( ! $found ) {
                return false;
            }
        }

        $patterns = array_filter( (array) ( $this->postal_patterns ?? [] ) );

        if ( [] !== $patterns ) {
            return TaxRate::postalMatches( implode( ',', $patterns ), $address->postalCode );
        }

        return true;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'country_codes'   => 'array',
            'region_codes'    => 'array',
            'postal_patterns' => 'array',
            'priority'        => 'integer',
            'is_active'       => 'boolean',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return ShippingZoneFactory
     */
    protected static function newFactory(): ShippingZoneFactory
    {
        return ShippingZoneFactory::new();
    }
}
