<?php

/**
 * LocalizedDate.
 *
 * Locale-aware date formatting for anything shown to end users (parent plan
 * §16.5): month and day names come from Carbon's translations, and the
 * default pattern itself is a translatable string so each locale orders
 * day, month, and year its own way. Moments are shown in the store time
 * zone ({@see StoreTimezone}).
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

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Formats dates with `Carbon::translatedFormat()`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class LocalizedDate
{
    /**
     * Formats `$date` for display, or returns an empty string when it is
     * empty or unparseable.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string|null  $date    Date or parseable string.
     * @param  string|null                    $format  PHP date format; defaults to the locale's long date pattern.
     * @param  string|null                    $locale  Locale; defaults to the application locale.
     *
     * @return string
     */
    public static function format( DateTimeInterface|string|null $date, ?string $format = null, ?string $locale = null ): string
    {
        if ( null === $date || '' === $date ) {
            return '';
        }

        $locale = $locale ?? (string) app()->getLocale();

        try {
            $carbon = $date instanceof DateTimeInterface ? Carbon::instance( $date ) : Carbon::parse( $date );
        } catch ( Throwable ) {
            return is_string( $date ) ? $date : '';
        }

        // A moment is shown in the store time zone; a bare date (`2026-03-01`)
        // has no time to convert and stays that date.
        if ( ! is_string( $date ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $date ) ) ) {
            $carbon = $carbon->copy()->setTimezone( StoreTimezone::name() );
        }

        return $carbon->locale( $locale )->translatedFormat( $format ?? __( 'F j, Y', [], $locale ) );
    }
}
