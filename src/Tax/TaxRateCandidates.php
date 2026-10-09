<?php

/**
 * Tax rate candidates.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Tax;

use ArtisanPackUI\Ecommerce\Models\TaxRate;
use Illuminate\Database\Eloquent\Collection;

/**
 * The active tax rates for a tax class and country, read once per request
 * (or queue job) however many prices are taxed — a product listing taxes
 * every product's display price with the same few rates.
 *
 * Bound scoped, so Octane requests and queued jobs start empty; saving or
 * deleting a {@see TaxRate} empties it too.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class TaxRateCandidates
{
    /**
     * Rates per `class|COUNTRY`.
     *
     * @since 1.0.0
     *
     * @var array<string, Collection<int, TaxRate>>
     */
    private array $rates = [];

    /**
     * The active rates for `$taxClassKey` in `$countryCode`, by priority
     * then id. Whether each matches a destination's region and postcode is
     * the caller's to check.
     *
     * @since 1.0.0
     *
     * @param  string  $taxClassKey  Tax class key.
     * @param  string  $countryCode  ISO 3166-1 alpha-2 country.
     *
     * @return Collection<int, TaxRate>
     */
    public function for( string $taxClassKey, string $countryCode ): Collection
    {
        $country = strtoupper( $countryCode );

        return $this->rates[ $taxClassKey . '|' . $country ] ??= TaxRate::query()
            ->active()
            ->where( 'tax_class_key', $taxClassKey )
            ->where( 'country_code', $country )
            ->orderBy( 'priority' )
            ->orderBy( 'id' )
            ->get();
    }

    /**
     * Forgets every rate read so far.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function flush(): void
    {
        $this->rates = [];
    }
}
