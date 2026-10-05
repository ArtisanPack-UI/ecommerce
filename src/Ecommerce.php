<?php

/**
 * Main Ecommerce class.
 *
 * The engine's front door, reached through the `Ecommerce` facade or the
 * `ecommerce()` helper: the installed version, migration control, and
 * typed accessors for the main services.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce;

use Composer\InstalledVersions;
use Throwable;

/**
 * Main Ecommerce class.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class Ecommerce
{
    /**
     * The Composer package name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PACKAGE = 'artisanpack-ui/ecommerce';

    /**
     * Whether the service provider loads the engine's migrations. Turned
     * off by {@see self::ignoreMigrations()}.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public static bool $runsMigrations = true;

    /**
     * Stops the service provider from loading the engine's migrations, for
     * hosts that publish them (`vendor:publish --tag=ecommerce-migrations`)
     * and run their own copies. Call it from a service provider's
     * `register()` method.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public static function ignoreMigrations(): static
    {
        static::$runsMigrations = false;

        return new static();
    }

    /**
     * Whether the service provider should load the engine's migrations.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public static function shouldRunMigrations(): bool
    {
        return static::$runsMigrations;
    }

    /**
     * The installed engine version (`1.0.0`), or `dev` when Composer can't
     * report it (a path repository without a version, for example).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function version(): string
    {
        try {
            if ( class_exists( InstalledVersions::class ) && InstalledVersions::isInstalled( self::PACKAGE ) ) {
                return (string) ( InstalledVersions::getPrettyVersion( self::PACKAGE ) ?? 'dev' );
            }
        } catch ( Throwable ) {
            // Composer's runtime data is unavailable; report a development build.
        }

        return 'dev';
    }
}
