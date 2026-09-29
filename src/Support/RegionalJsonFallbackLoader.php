<?php

/**
 * RegionalJsonFallbackLoader.
 *
 * Translation-loader decorator that lets a regional locale (`de_DE`,
 * `es-MX`, `fr_CA`) fall back to its base language's JSON catalogue.
 * Parent plan §16.5.
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

use Illuminate\Contracts\Translation\Loader;

/**
 * Merges base-language JSON lines under a regional locale's own.
 *
 * Laravel's translator never applies locale fallback to JSON keys: with
 * `app.locale = de_DE` and only a `de.json` catalogue, every `__()` call
 * returns English (the fallback locale only applies to PHP group files).
 * This decorator loads `de.json` first and lays `de_DE.json` on top, so
 * regional catalogues still win key by key. Every other load — PHP groups,
 * namespaced files, base locales — is passed straight through.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RegionalJsonFallbackLoader implements Loader
{
    /**
     * @since 1.0.0
     *
     * @param  Loader  $inner  The loader being decorated.
     */
    public function __construct( protected Loader $inner )
    {
    }

    /**
     * Forwards loader-specific calls (e.g. FileLoader's `addPath()`).
     *
     * @since 1.0.0
     *
     * @param  string             $method     Method name.
     * @param  array<int, mixed>  $arguments  Arguments.
     *
     * @return mixed
     */
    public function __call( string $method, array $arguments ): mixed
    {
        return $this->inner->{$method}( ...$arguments );
    }

    /**
     * @since 1.0.0
     *
     * @param  string       $locale     Locale.
     * @param  string       $group      Group (`*` for JSON).
     * @param  string|null  $namespace  Namespace (`*` for JSON).
     *
     * @return array<string, mixed>
     */
    public function load( $locale, $group, $namespace = null )
    {
        $lines = $this->inner->load( $locale, $group, $namespace );

        if ( '*' !== $group || '*' !== $namespace ) {
            return $lines;
        }

        $base = self::baseLocale( (string) $locale );

        if ( null === $base ) {
            return $lines;
        }

        return array_merge( $this->inner->load( $base, $group, $namespace ), $lines );
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $namespace  Namespace.
     * @param  string  $hint       Path.
     *
     * @return void
     */
    public function addNamespace( $namespace, $hint ): void
    {
        $this->inner->addNamespace( $namespace, $hint );
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $path  JSON catalogue directory.
     *
     * @return void
     */
    public function addJsonPath( $path ): void
    {
        $this->inner->addJsonPath( $path );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function namespaces()
    {
        return $this->inner->namespaces();
    }

    /**
     * The decorated loader.
     *
     * @since 1.0.0
     *
     * @return Loader
     */
    public function inner(): Loader
    {
        return $this->inner;
    }

    /**
     * The base language of a regional locale (`de_DE` → `de`), or null for
     * a locale without a region.
     *
     * @since 1.0.0
     *
     * @param  string  $locale  Locale.
     *
     * @return string|null
     */
    public static function baseLocale( string $locale ): ?string
    {
        $parts = preg_split( '/[_-]/', $locale, 2 );

        if ( false === $parts || 2 !== count( $parts ) || '' === $parts[0] ) {
            return null;
        }

        return $parts[0];
    }
}
