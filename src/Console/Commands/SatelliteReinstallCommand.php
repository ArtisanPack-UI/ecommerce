<?php

/**
 * SatelliteReinstallCommand.
 *
 * `php artisan ecommerce:satellite:reinstall {package}` — re-attaches a
 * satellite that `ecommerce:satellite:uninstall` deregistered (parent
 * plan §16.6).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Console\Commands;

use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use Illuminate\Console\Command;

/**
 * Clears a satellite's uninstalled mark. Its preserved tables and rows are
 * picked up again on the next boot; if it was purged, run its migrations
 * (`php artisan migrate`) first.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SatelliteReinstallCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ecommerce:satellite:reinstall
        {package : Composer package name of the satellite (vendor/package).}';

    /**
     * @var string
     */
    protected $description = 'Re-attach an uninstalled ecommerce satellite.';

    /**
     * @since 1.0.0
     *
     * @param  SatelliteRegistry  $registry  Satellite registry.
     *
     * @return int
     */
    public function handle( SatelliteRegistry $registry ): int
    {
        $package = (string) $this->argument( 'package' );

        $registry->sync();

        $row = $registry->record( $package );

        if ( null === $row ) {
            $this->error( __( 'Unknown satellite ":package". Run ecommerce:satellite:audit to list known satellites.', [ 'package' => $package ] ) );

            return self::FAILURE;
        }

        if ( ! $row->isUninstalled() ) {
            $this->info( __( 'Satellite ":package" is already installed.', [ 'package' => $package ] ) );

            return self::SUCCESS;
        }

        $registry->markReinstalled( $package );

        // Routes/config cached while the satellite was uninstalled lack its
        // registrations; clear both so it re-wires on the next boot.
        if ( method_exists( $this->laravel, 'configurationIsCached' ) && $this->laravel->configurationIsCached() ) {
            $this->callSilently( 'config:clear' );
        }

        if ( method_exists( $this->laravel, 'routesAreCached' ) && $this->laravel->routesAreCached() ) {
            $this->callSilently( 'route:clear' );
        }

        $this->info( __( 'Satellite ":package" reinstalled. It re-attaches to its existing data on the next boot.', [ 'package' => $package ] ) );

        return self::SUCCESS;
    }
}
