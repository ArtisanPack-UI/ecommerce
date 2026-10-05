<?php

/**
 * SupportedLocales.
 *
 * The locales the engine serves (`localization.supported_locales`, default
 * the shipped `en`, `es`, `fr`, `de`): negotiated from `Accept-Language`,
 * accepted as a customer's notification language, and used for translated
 * catalog notification copy.
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

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class SupportedLocales
{
    /**
     * Locales the engine ships catalogues for.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const SHIPPED = [ 'en', 'es', 'fr', 'de' ];

    /**
     * The configured locales.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $configured = config( 'artisanpack.ecommerce.localization.supported_locales' );

        return array_values( array_filter( is_array( $configured ) ? $configured : self::SHIPPED, static fn ( mixed $locale ): bool => is_string( $locale ) && '' !== $locale ) );
    }

    /**
     * Whether `$locale`, or the language it's a regional variant of
     * (`de_DE` → `de`), is supported.
     *
     * @since 1.0.0
     *
     * @param  string  $locale  Locale.
     *
     * @return bool
     */
    public static function covers( string $locale ): bool
    {
        $base = self::language( $locale );

        foreach ( self::all() as $supported ) {
            if ( $supported === $locale || strtolower( $supported ) === $base ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The language part of `$locale` (`pt-BR` → `pt`), lowercased.
     *
     * @since 1.0.0
     *
     * @param  string  $locale  Locale.
     *
     * @return string
     */
    public static function language( string $locale ): string
    {
        return strtolower( (string) preg_split( '/[_-]/', $locale )[0] );
    }
}
