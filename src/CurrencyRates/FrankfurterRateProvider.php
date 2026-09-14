<?php

/**
 * FrankfurterRateProvider.
 *
 * FX-rate source backed by the free public Frankfurter API
 * (`https://api.frankfurter.dev/`). Frankfurter fronts the European Central
 * Bank reference rates, which are quoted once per business day — so rates
 * are cached at the daily granularity to avoid pounding the endpoint.
 *
 * Engine spec §4.7, registry entry `frankfurter`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\CurrencyRates;

use ArtisanPackUI\Ecommerce\Contracts\CurrencyRateProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Money\Currency;
use RuntimeException;

/**
 * FrankfurterRateProvider.
 *
 * Rate lookup uses `GET /latest?base={from}&symbols={to}` and multiplies the
 * returned decimal rate by `10^8`. The `Money\Money` layer never sees the
 * decimal — only the E8 integer — so the float only exists inside this class
 * for the width of one arithmetic operation.
 *
 * Cache key is scoped by day (UTC) so a single upstream call serves every
 * hit on a given business day; a daily scheduled command
 * (`ecommerce:refresh-fx-rates`) is expected to warm the cache proactively.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class FrankfurterRateProvider implements CurrencyRateProvider
{
    /**
     * Registry key.
     *
     * @since 1.0.0
     */
    public const KEY = 'frankfurter';

    /**
     * Default API base URL when the configuration does not override it.
     *
     * @since 1.0.0
     */
    private const DEFAULT_BASE_URL = 'https://api.frankfurter.dev/v1';

    /**
     * Constructs the provider.
     *
     * @since 1.0.0
     *
     * @param  HttpFactory      $http    Laravel HTTP client factory.
     * @param  CacheRepository  $cache   Cache repository used to memoise daily rates.
     * @param  ConfigRepository $config  Config repository (for `frankfurter.base_url` + `cache_ttl`).
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @param  Currency  $from  Source currency.
     * @param  Currency  $to    Target currency.
     *
     * @throws RuntimeException When the upstream response is missing or malformed.
     *
     * @return int
     */
    public function getRateE8( Currency $from, Currency $to ): int
    {
        $fromCode = strtoupper( $from->getCode() );
        $toCode   = strtoupper( $to->getCode() );

        if ( $fromCode === $toCode ) {
            return 100_000_000;
        }

        $cacheKey = sprintf(
            'artisanpack.ecommerce.currency.frankfurter.%s.%s.%s',
            Carbon::now( 'UTC' )->format( 'Y-m-d' ),
            $fromCode,
            $toCode,
        );

        /** @var int $ttl */
        $ttl = (int) $this->config->get( 'artisanpack.ecommerce.currency.frankfurter.cache_ttl', 86_400 );

        return $this->cache->remember( $cacheKey, $ttl, function () use ( $fromCode, $toCode ): int {
            return $this->fetchRateE8( $fromCode, $toCode );
        } );
    }

    /**
     * Calls Frankfurter and converts the returned decimal to a signed E8 int.
     *
     * @since 1.0.0
     *
     * @param  string  $from  ISO 4217 source code.
     * @param  string  $to    ISO 4217 target code.
     *
     * @throws RuntimeException When the response is not 2xx or is missing the expected rate key.
     *
     * @return int
     */
    private function fetchRateE8( string $from, string $to ): int
    {
        /** @var string $baseUrl */
        $baseUrl = (string) $this->config->get( 'artisanpack.ecommerce.currency.frankfurter.base_url', self::DEFAULT_BASE_URL );

        /** @var int $timeout */
        $timeout = (int) $this->config->get( 'artisanpack.ecommerce.currency.frankfurter.timeout', 5 );

        // Bounded timeout so a slow upstream never blocks a storefront render
        // longer than the operator has explicitly opted into. Default 5s is
        // well above Frankfurter's typical response time.
        $response = $this->http->acceptJson()->timeout( $timeout )->get( rtrim( $baseUrl, '/' ) . '/latest', [
            'base'    => $from,
            'symbols' => $to,
        ] );

        if ( ! $response->successful() ) {
            throw new RuntimeException(
                sprintf(
                    'Frankfurter returned HTTP %d fetching %s → %s.',
                    $response->status(),
                    $from,
                    $to,
                ),
            );
        }

        /** @var array{rates?: array<string, float|int|string>} $body */
        $body = (array) $response->json();
        $rate = $body[ 'rates' ][ $to ] ?? null;

        if ( ! is_numeric( $rate ) ) {
            throw new RuntimeException(
                sprintf( 'Frankfurter response for %s → %s did not include a numeric rate.', $from, $to ),
            );
        }

        // Multiply as a string via bcmath-style rounding without floats: the
        // API's rates are documented as decimal strings with up to 4-6
        // fractional digits, so multiplying via number_format keeps the
        // arithmetic exact for the range we care about.
        $scaled = (string) $rate;

        if ( 1 !== preg_match( '/^-?\d+(\.\d+)?$/', $scaled ) ) {
            throw new RuntimeException(
                sprintf( 'Frankfurter response for %s → %s had an unparseable rate "%s".', $from, $to, $scaled ),
            );
        }

        [ $whole, $fraction ] = array_pad( explode( '.', $scaled, 2 ), 2, '' );
        $fraction             = substr( str_pad( $fraction, 8, '0' ), 0, 8 );

        $e8 = (int) ( '' === ltrim( $whole, '0' ) ? '0' : $whole ) * 100_000_000
            + (int) ( '' === $fraction ? '0' : $fraction );

        if ( $e8 <= 0 ) {
            throw new RuntimeException(
                sprintf( 'Frankfurter returned a non-positive rate for %s → %s.', $from, $to ),
            );
        }

        return $e8;
    }
}
