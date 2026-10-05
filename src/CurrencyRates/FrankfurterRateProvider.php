<?php

/**
 * FrankfurterRateProvider.
 *
 * FX-rate source backed by the free public Frankfurter API
 * (`https://api.frankfurter.dev/`). Frankfurter fronts the European Central
 * Bank reference rates, which are quoted once per business day — so rates
 * are cached at the daily granularity to avoid pounding the endpoint.
 *
 * Every rate fetched is also kept as the pair's last good rate. When
 * Frankfurter can't be reached, that rate is used (with a warning in the
 * `ecommerce` log) instead of failing every conversion, and the fallback is
 * cached briefly so an outage doesn't add a timeout to each request.
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

use ArtisanPackUI\Ecommerce\Contracts\RefreshableRateProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Money\Currency;
use RuntimeException;
use Throwable;

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
final class FrankfurterRateProvider implements RefreshableRateProvider
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
     * Seconds a last-good fallback stands in for today's rate before the
     * remote is tried again.
     *
     * @since 1.0.0
     *
     * @var int
     */
    private const FALLBACK_TTL = 300;

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

        $cached = $this->cache->get( $this->dailyKey( $fromCode, $toCode ) );

        if ( is_int( $cached ) && $cached > 0 ) {
            return $cached;
        }

        try {
            return $this->refresh( $fromCode, $toCode );
        } catch ( Throwable $exception ) {
            $lastGood = $this->cache->get( $this->lastGoodKey( $fromCode, $toCode ) );

            if ( ! is_int( $lastGood ) || $lastGood <= 0 ) {
                throw $exception;
            }

            Log::channel( 'ecommerce' )->warning( 'Frankfurter is unavailable; using the last good exchange rate.', [
                'from'  => $fromCode,
                'to'    => $toCode,
                'rate'  => $lastGood,
                'error' => $exception->getMessage(),
            ] );

            // Don't retry the remote on every request during an outage.
            $this->cache->put( $this->dailyKey( $fromCode, $toCode ), $lastGood, self::FALLBACK_TTL );

            return $lastGood;
        }
    }

    /**
     * Fetches the rate from Frankfurter now, caching it for the day and as
     * the pair's last good rate.
     *
     * @since 1.0.0
     *
     * @param  Currency  $from  Source currency.
     * @param  Currency  $to    Target currency.
     *
     * @throws RuntimeException When Frankfurter can't provide the rate.
     *
     * @return int
     */
    public function refreshRateE8( Currency $from, Currency $to ): int
    {
        $fromCode = strtoupper( $from->getCode() );
        $toCode   = strtoupper( $to->getCode() );

        return $fromCode === $toCode ? 100_000_000 : $this->refresh( $fromCode, $toCode );
    }

    /**
     * Fetches and caches one pair.
     *
     * @since 1.0.0
     *
     * @param  string  $from  Source code.
     * @param  string  $to    Target code.
     *
     * @return int
     */
    private function refresh( string $from, string $to ): int
    {
        $rate = $this->fetchRateE8( $from, $to );
        $ttl  = (int) $this->config->get( 'artisanpack.ecommerce.currency.frankfurter.cache_ttl', 86_400 );

        $this->cache->put( $this->dailyKey( $from, $to ), $rate, $ttl );
        $this->cache->forever( $this->lastGoodKey( $from, $to ), $rate );

        return $rate;
    }

    /**
     * Cache key for today's rate (ECB rates change once per business day).
     *
     * @since 1.0.0
     *
     * @param  string  $from  Source code.
     * @param  string  $to    Target code.
     *
     * @return string
     */
    private function dailyKey( string $from, string $to ): string
    {
        return sprintf( 'artisanpack.ecommerce.currency.frankfurter.%s.%s.%s', Carbon::now( 'UTC' )->format( 'Y-m-d' ), $from, $to );
    }

    /**
     * Cache key for the pair's last good rate (no date).
     *
     * @since 1.0.0
     *
     * @param  string  $from  Source code.
     * @param  string  $to    Target code.
     *
     * @return string
     */
    private function lastGoodKey( string $from, string $to ): string
    {
        return sprintf( 'artisanpack.ecommerce.currency.frankfurter.last-good.%s.%s', $from, $to );
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

        // JSON numbers decode as floats, and a tiny rate (IDR, VND: 0.000061)
        // casts to "6.1E-5". Format it as a plain decimal and round half up
        // to eight places.
        $decimal = is_string( $rate ) ? trim( $rate ) : rtrim( rtrim( sprintf( '%.12F', (float) $rate ), '0' ), '.' );

        if ( 1 !== preg_match( '/^\d+(\.\d+)?$/', $decimal ) ) {
            throw new RuntimeException(
                sprintf( 'Frankfurter response for %s → %s had an unparseable rate "%s".', $from, $to, $decimal ),
            );
        }

        $e8 = (int) bcadd( bcmul( $decimal, '100000000', 4 ), '0.5', 0 );

        if ( $e8 <= 0 ) {
            throw new RuntimeException(
                sprintf( 'Frankfurter returned a non-positive rate for %s → %s.', $from, $to ),
            );
        }

        return $e8;
    }
}
