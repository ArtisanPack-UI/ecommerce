<?php

/**
 * RefreshableRateProvider contract.
 *
 * Optional companion to {@see CurrencyRateProvider} for providers that cache
 * rates fetched from a remote service. `ecommerce:refresh-fx-rates` calls
 * {@see self::refreshRateE8()} to fetch and cache each rate ahead of
 * shoppers asking for it; providers without this contract are warmed with a
 * plain {@see CurrencyRateProvider::getRateE8()} call.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use Money\Currency;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface RefreshableRateProvider extends CurrencyRateProvider
{
    /**
     * Fetches the current rate from the remote service (ignoring any cached
     * value), caches it, and returns it as E8.
     *
     * @since 1.0.0
     *
     * @param  Currency  $from  Source currency.
     * @param  Currency  $to    Target currency.
     *
     * @throws Throwable When the service can't provide the rate.
     *
     * @return int
     */
    public function refreshRateE8( Currency $from, Currency $to ): int;
}
