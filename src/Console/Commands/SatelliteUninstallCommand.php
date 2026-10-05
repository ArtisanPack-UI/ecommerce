<?php

/**
 * SatelliteUninstallCommand.
 *
 * `php artisan ecommerce:satellite:uninstall {package} [--purge]` — the
 * first-class satellite uninstall path (parent plan §16.6).
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

use ArtisanPackUI\Ecommerce\Contracts\SatelliteUninstaller;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\Ecommerce\Satellites\NullSatelliteUninstaller;
use ArtisanPackUI\Ecommerce\Satellites\SatelliteDescriptor;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Deregisters a satellite, preserving its data unless `--purge` is given.
 *
 * Default: runs the satellite's {@see SatelliteUninstaller} (unbinding
 * queue / scheduled / broadcast services), marks it uninstalled so its
 * routes, config, and bindings stop wiring on the next boot, and clears
 * cached config and routes. Its tables and rows stay put, so
 * `ecommerce:satellite:reinstall` re-attaches it without data loss.
 *
 * `--purge`: additionally rolls back every migration in the satellite's
 * declared migration paths (its `down()` methods drop its tables and
 * columns). The paths are stored on the satellite's row at sync time, so
 * a purge still works after the package stopped booting — as long as
 * its migration files are still on disk. Purge before `composer remove`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SatelliteUninstallCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ecommerce:satellite:uninstall
        {package : Composer package name of the satellite (vendor/package).}
        {--purge : Also roll back the satellite\'s migrations, dropping its tables and columns.}
        {--force : Skip the confirmation prompt.}';

    /**
     * @var string
     */
    protected $description = 'Deregister an ecommerce satellite (data preserved unless --purge).';

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
        $purge   = (bool) $this->option( 'purge' );

        $registry->sync();

        $row = $registry->record( $package );

        if ( null === $row ) {
            $this->error( __( 'Unknown satellite ":package". Run ecommerce:satellite:audit to list known satellites.', [ 'package' => $package ] ) );

            return self::FAILURE;
        }

        if ( $row->isUninstalled() && ! $purge ) {
            $this->info( __( 'Satellite ":package" is already uninstalled.', [ 'package' => $package ] ) );

            return self::SUCCESS;
        }

        $descriptor = $registry->has( $package ) ? $registry->get( $package ) : SatelliteDescriptor::fromModel( $row );

        $purgePaths = $purge ? $this->purgePaths( $descriptor ) : [];

        if ( null === $purgePaths ) {
            return self::FAILURE;
        }

        if ( ! $this->confirmed( $descriptor, $purge ) ) {
            $this->warn( __( 'Uninstall cancelled.' ) );

            return self::FAILURE;
        }

        $this->uninstaller( $descriptor )->uninstall( $descriptor, $purge );

        if ( $purge ) {
            $this->purge( $descriptor, $purgePaths );
        }

        $registry->markUninstalled( $package );
        $this->clearCaches();

        $this->info( $purge
            ? __( 'Satellite ":package" uninstalled and its schema purged.', [ 'package' => $package ] )
            : __( 'Satellite ":package" uninstalled. Its data is preserved; run ecommerce:satellite:reinstall to re-attach it.', [ 'package' => $package ] ) );

        return self::SUCCESS;
    }

    /**
     * Asks for confirmation unless `--force` is set. Non-interactive runs
     * and production refuse without `--force`.
     *
     * @since 1.0.0
     *
     * @param  SatelliteDescriptor  $descriptor  Satellite.
     * @param  bool                 $purge       Whether tables will be dropped.
     *
     * @return bool
     */
    protected function confirmed( SatelliteDescriptor $descriptor, bool $purge ): bool
    {
        if ( (bool) $this->option( 'force' ) ) {
            return true;
        }

        // Destructive and irreversible: never proceed silently. Scripts,
        // CI jobs, and production must opt in with --force.
        if ( ! $this->input->isInteractive() || $this->laravel->environment( 'production' ) ) {
            $this->error( __( 'Refusing to uninstall without confirmation. Re-run with --force.' ) );

            return false;
        }

        return $this->confirm( $purge
            ? __( 'Uninstall ":name" and DROP its tables? This cannot be undone.', [ 'name' => $descriptor->displayName() ] )
            : __( 'Uninstall ":name"? Its data will be preserved.', [ 'name' => $descriptor->displayName() ] ) );
    }

    /**
     * Resolves the satellite's uninstaller, falling back to a no-op when it
     * declares none or its class is gone.
     *
     * @since 1.0.0
     *
     * @param  SatelliteDescriptor  $descriptor  Satellite.
     *
     * @return SatelliteUninstaller
     */
    protected function uninstaller( SatelliteDescriptor $descriptor ): SatelliteUninstaller
    {
        $class = $descriptor->uninstaller;

        if ( null === $class ) {
            return new NullSatelliteUninstaller();
        }

        if ( ! class_exists( $class ) || ! is_subclass_of( $class, SatelliteUninstaller::class ) ) {
            $this->warn( __( 'Uninstaller ":class" is unavailable; skipping service teardown.', [ 'class' => $class ] ) );

            return new NullSatelliteUninstaller();
        }

        return $this->laravel->make( $class );
    }

    /**
     * Resolves and validates the satellite's migration paths before
     * anything is changed. Missing directories are skipped with a warning;
     * a path that holds the host's or the engine's own migrations aborts
     * the whole uninstall.
     *
     * @since 1.0.0
     *
     * @param  SatelliteDescriptor  $descriptor  Satellite.
     *
     * @return array<int, string>|null Resolved paths, or null when refused.
     */
    protected function purgePaths( SatelliteDescriptor $descriptor ): ?array
    {
        $paths = [];

        foreach ( $descriptor->migrationPaths as $path ) {
            $resolved = str_starts_with( $path, DIRECTORY_SEPARATOR ) ? $path : $this->laravel->basePath( $path );

            if ( ! is_dir( $resolved ) ) {
                $this->warn( __( 'Migration path ":path" does not exist; skipping it.', [ 'path' => $path ] ) );

                continue;
            }

            if ( $this->isSharedMigrationPath( $resolved ) ) {
                $this->error( __( 'Refusing to roll back ":path": it holds the application\'s or the engine\'s own migrations. A satellite\'s migration paths must be its own directories.', [ 'path' => $path ] ) );

                return null;
            }

            $paths[] = $resolved;
        }

        return $paths;
    }

    /**
     * Rolls back the satellite's migrations, dropping its tables/columns.
     *
     * @since 1.0.0
     *
     * @param  SatelliteDescriptor  $descriptor  Satellite.
     * @param  array<int, string>   $paths       Paths from {@see self::purgePaths()}.
     *
     * @return void
     */
    protected function purge( SatelliteDescriptor $descriptor, array $paths ): void
    {
        if ( [] === $paths ) {
            $this->warn( __( 'No migrations to roll back for ":package".', [ 'package' => $descriptor->packageName ] ) );

            return;
        }

        /** @var Migrator $migrator */
        $migrator = $this->laravel->make( 'migrator' );

        if ( ! $migrator->repositoryExists() ) {
            $this->warn( __( 'The migrations table does not exist; nothing to roll back.' ) );

            return;
        }

        $before = Schema::getTableListing( null, false );

        // Silence the migrator: reset() walks every ran migration and prints
        // "Migration not found" for each one outside `$paths`, which reads
        // alarmingly like the host schema is being touched. Only the
        // satellite's own migrations are rolled back; report what dropped.
        $migrator->setOutput( new NullOutput() )->reset( $paths );

        foreach ( array_diff( $before, Schema::getTableListing( null, false ) ) as $table ) {
            $this->line( __( 'Dropped table :table', [ 'table' => $table ] ) );
        }
    }

    /**
     * Whether `$path` is (or contains) the host application's migrations
     * directory or the engine's own. `Migrator::reset()` rolls back every
     * ran migration found under the given paths, so a satellite declaring
     * `database/migrations` would otherwise take the host schema with it.
     * Dedicated sub-directories (e.g. `database/migrations/my-satellite`)
     * are fine: Laravel never scans them on its own.
     *
     * @since 1.0.0
     *
     * @param  string  $path  Resolved migration path.
     *
     * @return bool
     */
    protected function isSharedMigrationPath( string $path ): bool
    {
        $candidate = rtrim( (string) ( realpath( $path ) ?: $path ), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

        foreach ( [ $this->laravel->databasePath( 'migrations' ), __DIR__ . '/../../../database/migrations', $this->laravel->basePath() ] as $protected ) {
            $protected = rtrim( (string) ( realpath( $protected ) ?: $protected ), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

            if ( str_starts_with( $protected, $candidate ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clears cached config and routes so the satellite's registrations
     * don't survive in the caches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function clearCaches(): void
    {
        if ( method_exists( $this->laravel, 'configurationIsCached' ) && $this->laravel->configurationIsCached() ) {
            $this->callSilently( 'config:clear' );
        }

        if ( method_exists( $this->laravel, 'routesAreCached' ) && $this->laravel->routesAreCached() ) {
            $this->callSilently( 'route:clear' );
        }
    }
}
