<?php

/**
 * TaxRateMath.
 *
 * Integer / decimal-string arithmetic for `tax_rates.rate_ubps` so a tax
 * rate is never handled as a float (engine spec §2.4, parent plan §5.11).
 *
 * Scale: `rate_ubps / 1_000_000_000` is the fractional rate, matching the
 * worked examples in the spec (8.375% = 83_750_000; 8.75% = 87_500_000).
 * All arithmetic goes through bcmath (a hard requirement of moneyphp v4).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class TaxRateMath
{
    /**
     * `rate_ubps` value representing a 100% rate.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const UNITS_PER_WHOLE = 1_000_000_000;

    /**
     * Decimal places used for intermediate bcmath results.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const SCALE = 18;

    /**
     * Converts a `rate_ubps` integer to a fractional decimal string
     * (`83_750_000` → `"0.083750000000000000"`).
     *
     * @since 1.0.0
     *
     * @param  int  $ubps  Rate in micro-basis-points.
     *
     * @return string
     */
    public static function toFraction( int $ubps ): string
    {
        return bcdiv( (string) $ubps, (string) self::UNITS_PER_WHOLE, self::SCALE );
    }

    /**
     * Converts a percentage string (`"8.375"`) to `rate_ubps`
     * (`83_750_000`). Accepts at most nine fractional digits of a
     * fraction — anything finer cannot be represented and throws.
     *
     * @since 1.0.0
     *
     * @param  string  $percent  Numeric percentage string.
     *
     * @throws InvalidArgumentException When `$percent` is not numeric or exceeds the representable precision.
     *
     * @return int
     */
    public static function fromPercent( string $percent ): int
    {
        $percent = trim( $percent );

        if ( ! is_numeric( $percent ) || str_contains( strtolower( $percent ), 'e' ) ) {
            throw new InvalidArgumentException( sprintf( 'Tax percentage "%s" is not a plain decimal number.', $percent ) );
        }

        $units = bcmul( $percent, (string) ( self::UNITS_PER_WHOLE / 100 ), self::SCALE );

        if ( 0 !== bccomp( $units, bcadd( $units, '0', 0 ), self::SCALE ) ) {
            throw new InvalidArgumentException( sprintf( 'Tax percentage "%s" is more precise than rate_ubps can store.', $percent ) );
        }

        return (int) bcadd( $units, '0', 0 );
    }

    /**
     * Converts `rate_ubps` to a human percentage string (`83_750_000` → `"8.375"`).
     *
     * @since 1.0.0
     *
     * @param  int  $ubps  Rate in micro-basis-points.
     *
     * @return string
     */
    public static function toPercent( int $ubps ): string
    {
        $percent = bcdiv( (string) $ubps, (string) ( self::UNITS_PER_WHOLE / 100 ), 7 );

        return str_contains( $percent, '.' ) ? rtrim( rtrim( $percent, '0' ), '.' ) : $percent;
    }

    /**
     * Returns `1 + rate` as a decimal string, for inclusive back-calculation.
     *
     * @since 1.0.0
     *
     * @param  int  $ubps  Rate in micro-basis-points.
     *
     * @return string
     */
    public static function onePlus( int $ubps ): string
    {
        return bcadd( '1', self::toFraction( $ubps ), self::SCALE );
    }
}
