<?php

/**
 * ConfigRateProvider.
 *
 * Static FX-rate source that reads its rate table from configuration
 * (`artisanpack.ecommerce.currency.rates`). Useful in tests, in offline
 * deployments, and as an override in front of {@see FrankfurterRateProvider}
 * for currency pairs the app wants to pin.
 *
 * Engine spec §4.7, registry entry `config`.
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
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Money\Currency;
use RuntimeException;

/**
 * ConfigRateProvider.
 *
 * Rate lookup order for `$from → $to`:
 *   1. Identity (`from === to`) — returns `10^8`.
 *   2. Exact `rates.{from}.{to}` entry in config.
 *   3. Inverse `rates.{to}.{from}` entry (rounded to nearest E8 unit).
 *
 * Every configured rate must be either a positive integer already expressed
 * in E8 units, or a numeric string with the same shape — floats are rejected
 * for the same reason {@see \ArtisanPackUI\Ecommerce\Casts\MoneyCast} rejects
 * them: `0.1 + 0.2` must never enter the money pipeline.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ConfigRateProvider implements CurrencyRateProvider
{
    /**
     * Registry key.
     *
     * @since 1.0.0
     */
    public const KEY = 'config';

    /**
     * FX-rate table pulled from configuration at construction time.
     *
     * @since 1.0.0
     *
     * @var array<string, array<string, int|string>>
     */
    private readonly array $rates;

    /**
     * Constructs the provider from the given config repository.
     *
     * @since 1.0.0
     *
     * @param  Repository  $config  Laravel config repository.
     */
    public function __construct( Repository $config )
    {
        /** @var array<string, array<string, int|string>> $rates */
        $rates       = (array) $config->get( 'artisanpack.ecommerce.currency.rates', [] );
        $this->rates = $rates;
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
     * @throws RuntimeException When no rate (direct or inverse) is configured.
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

        $direct = $this->rates[ $fromCode ][ $toCode ] ?? null;

        if ( null !== $direct ) {
            return $this->normaliseE8( $direct, $fromCode, $toCode );
        }

        $inverse = $this->rates[ $toCode ][ $fromCode ] ?? null;

        if ( null !== $inverse ) {
            $inverseE8 = $this->normaliseE8( $inverse, $toCode, $fromCode );

            // (10^8)^2 / inverse, rounded half-up. intdiv() truncates, so add
            // half of the divisor before dividing to get commercial rounding.
            $numerator = 10 ** 16;

            return intdiv( $numerator + intdiv( $inverseE8, 2 ), $inverseE8 );
        }

        throw new RuntimeException(
            sprintf( 'No configured FX rate for %s → %s.', $fromCode, $toCode ),
        );
    }

    /**
     * Coerces a configured rate value into a signed E8 integer.
     *
     * @since 1.0.0
     *
     * @param  int|string  $value  Configured rate value.
     * @param  string      $from   Source currency (for error messages).
     * @param  string      $to     Target currency (for error messages).
     *
     * @throws InvalidArgumentException When the value is not a positive integer/integer string.
     *
     * @return int
     */
    private function normaliseE8( int|string $value, string $from, string $to ): int
    {
        if ( is_int( $value ) ) {
            if ( $value <= 0 ) {
                throw new InvalidArgumentException(
                    sprintf( 'Configured FX rate for %s → %s must be positive; got %d.', $from, $to, $value ),
                );
            }

            return $value;
        }

        if ( 1 !== preg_match( '/^\d+$/', $value ) ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Configured FX rate for %s → %s must be a positive integer or integer-string in E8 units; got "%s".',
                    $from,
                    $to,
                    $value,
                ),
            );
        }

        $filtered = filter_var( $value, FILTER_VALIDATE_INT );

        if ( false === $filtered || $filtered <= 0 ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Configured FX rate for %s → %s exceeds the signed 64-bit range or is not positive.',
                    $from,
                    $to,
                ),
            );
        }

        return $filtered;
    }
}
