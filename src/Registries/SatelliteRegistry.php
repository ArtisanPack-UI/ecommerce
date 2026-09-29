<?php

/**
 * SatelliteRegistry.
 *
 * Runtime registry of the satellite packages extending the engine,
 * backed by the `ecommerce_satellites` table (engine spec §3.32 + §5
 * row 16, parent plan §16.6). Bound as a singleton in
 * {@see \ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Registries;

use ArtisanPackUI\Ecommerce\Models\Satellite;
use ArtisanPackUI\Ecommerce\Satellites\SatelliteDescriptor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Registry of satellite descriptors.
 *
 * Satellites call {@see self::register()} from their service-provider
 * `boot()`. Registration is in-memory — nothing is written per request.
 * The descriptor is persisted by {@see self::sync()}, which the
 * `ecommerce:satellite:*` commands run first.
 *
 * `register()` answers whether the satellite is *active*. A satellite
 * that `ecommerce:satellite:uninstall` marked uninstalled stays inactive
 * — even while its package is still installed — until
 * `ecommerce:satellite:reinstall` re-attaches it. Satellites guard their
 * route, config, and binding wiring on that answer:
 *
 * ```php
 * if ( ! $registry->register( [ 'package_name' => 'acme/ecommerce-subscriptions', … ] ) ) {
 *     return;
 * }
 * ```
 *
 * The product types an inactive satellite declares are removed from the
 * {@see ProductTypeRegistry} once the app has booted, so its products
 * resolve to the read-only `MissingProductType` placeholder even if the
 * satellite registered them without checking.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SatelliteRegistry
{
    /**
     * Cache key holding the uninstalled package names.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CACHE_KEY = 'artisanpack.ecommerce.satellites.uninstalled';

    /**
     * Registered descriptors keyed by package name.
     *
     * @since 1.0.0
     *
     * @var array<string, SatelliteDescriptor>
     */
    private array $descriptors = [];

    /**
     * In-process memo of the uninstalled package names.
     *
     * @since 1.0.0
     *
     * @var array<int, string>|null
     */
    private ?array $uninstalled = null;

    /**
     * @since 1.0.0
     *
     * @param  Application  $container  Application (environment + boot callbacks).
     */
    public function __construct( private readonly Application $container )
    {
    }

    /**
     * Registers a satellite and reports whether it is active.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>|SatelliteDescriptor  $descriptor  Descriptor, or its snake_case array form.
     *
     * @throws InvalidArgumentException When the descriptor is invalid, or the package is registered twice in `local`/`testing`.
     *
     * @return bool Whether the satellite is active (not uninstalled).
     */
    public function register( SatelliteDescriptor|array $descriptor ): bool
    {
        $descriptor = is_array( $descriptor ) ? SatelliteDescriptor::fromArray( $descriptor ) : $descriptor;
        $package    = $descriptor->packageName;

        if ( isset( $this->descriptors[ $package ] ) ) {
            $message = sprintf( 'Satellite "%s" is already registered; the new descriptor overwrites the previous one.', $package );

            if ( in_array( $this->container->environment(), [ 'local', 'testing' ], true ) ) {
                throw new InvalidArgumentException( $message );
            }

            Log::warning( $message );
        }

        $this->descriptors[ $package ] = $descriptor;

        if ( $this->isActive( $package ) ) {
            return true;
        }

        if ( [] !== $descriptor->productTypes ) {
            $this->container->booted( function () use ( $descriptor ): void {
                $types = $this->container->make( ProductTypeRegistry::class );

                foreach ( $descriptor->productTypes as $key ) {
                    if ( $this->ownsProductType( $descriptor, $key, $types ) ) {
                        $types->forget( $key );
                    }
                }
            } );
        }

        return false;
    }

    /**
     * Whether a satellite registered in this process.
     *
     * @since 1.0.0
     *
     * @param  string  $packageName  Composer package name.
     *
     * @return bool
     */
    public function has( string $packageName ): bool
    {
        return isset( $this->descriptors[ $packageName ] );
    }

    /**
     * The descriptor a satellite registered in this process.
     *
     * @since 1.0.0
     *
     * @param  string  $packageName  Composer package name.
     *
     * @throws RuntimeException When the package did not register.
     *
     * @return SatelliteDescriptor
     */
    public function get( string $packageName ): SatelliteDescriptor
    {
        return $this->descriptors[ $packageName ]
            ?? throw new RuntimeException( sprintf( 'No satellite registered under "%s".', $packageName ) );
    }

    /**
     * Every descriptor registered in this process, keyed by package name.
     *
     * @since 1.0.0
     *
     * @return array<string, SatelliteDescriptor>
     */
    public function all(): array
    {
        return $this->descriptors;
    }

    /**
     * Registered descriptors that are active.
     *
     * @since 1.0.0
     *
     * @return array<string, SatelliteDescriptor>
     */
    public function active(): array
    {
        return array_filter(
            $this->descriptors,
            fn ( SatelliteDescriptor $descriptor ): bool => $this->isActive( $descriptor->packageName ),
        );
    }

    /**
     * Whether a satellite is active — i.e. not marked uninstalled. A
     * package the engine has never seen counts as active.
     *
     * @since 1.0.0
     *
     * @param  string  $packageName  Composer package name.
     *
     * @return bool
     */
    public function isActive( string $packageName ): bool
    {
        return ! in_array( $packageName, $this->uninstalledPackages(), true );
    }

    /**
     * Package names marked uninstalled, read through the cache. When the
     * table does not exist yet or the database is unreachable, every
     * satellite is treated as active and nothing is cached.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function uninstalledPackages(): array
    {
        if ( null !== $this->uninstalled ) {
            return $this->uninstalled;
        }

        try {
            $cached = Cache::get( self::CACHE_KEY );

            if ( is_array( $cached ) ) {
                return $this->uninstalled = $cached;
            }
        } catch ( Throwable $e ) {
            // A cache outage must not quietly re-activate uninstalled
            // satellites: fall through to the database.
            Log::warning( 'Satellite registry cache unavailable; reading the uninstalled set from the database.', [ 'exception' => $e->getMessage() ] );
        }

        try {
            if ( ! Schema::hasTable( ( new Satellite() )->getTable() ) ) {
                return [];
            }

            $packages = Satellite::query()->uninstalled()->pluck( 'package_name' )->all();
        } catch ( Throwable ) {
            // No database yet (fresh install, `package:discover`): nothing
            // can have been uninstalled.
            return [];
        }

        try {
            Cache::forever( self::CACHE_KEY, $packages );
        } catch ( Throwable ) {
            // Serve from the database until the cache is back.
        }

        return $this->uninstalled = $packages;
    }

    /**
     * Forgets the cached uninstalled set. The lifecycle commands call this
     * after flipping a satellite's state.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function flush(): void
    {
        $this->uninstalled = null;

        try {
            Cache::forget( self::CACHE_KEY );
        } catch ( Throwable ) {
            // Nothing cached to forget.
        }
    }

    /**
     * Upserts a row for every satellite registered in this process. The
     * install state (`uninstalled_at`) and verification hash are left
     * alone; `registered_at` is set when the row is first created.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function sync(): void
    {
        foreach ( $this->descriptors as $descriptor ) {
            $this->persist( $descriptor );
        }
    }

    /**
     * The persisted row for a satellite, if any.
     *
     * @since 1.0.0
     *
     * @param  string  $packageName  Composer package name.
     *
     * @return Satellite|null
     */
    public function record( string $packageName ): ?Satellite
    {
        return Satellite::query()->where( 'package_name', $packageName )->first();
    }

    /**
     * Stores the sha256 of a satellite's contract-verify report (parent
     * plan §15.2).
     *
     * @since 1.0.0
     *
     * @param  string  $packageName  Composer package name.
     * @param  string  $reportHash   Hex sha256 of the report.
     *
     * @throws InvalidArgumentException When the hash is not a sha256 hex digest.
     * @throws RuntimeException         When the satellite is neither registered nor persisted.
     *
     * @return void
     */
    public function recordVerification( string $packageName, string $reportHash ): void
    {
        $reportHash = strtolower( $reportHash );

        if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $reportHash ) ) {
            throw new InvalidArgumentException( 'The verify report hash must be a sha256 hex digest.' );
        }

        $row = $this->record( $packageName )
            ?? ( $this->has( $packageName ) ? $this->persist( $this->get( $packageName ) ) : null )
            ?? throw new RuntimeException( sprintf( 'No satellite registered under "%s".', $packageName ) );

        $row->forceFill( [ 'verified_report_hash' => $reportHash ] )->save();
    }

    /**
     * Marks a satellite uninstalled.
     *
     * @since 1.0.0
     *
     * @param  string  $packageName  Composer package name.
     *
     * @throws RuntimeException When the satellite has no row.
     *
     * @return void
     */
    public function markUninstalled( string $packageName ): void
    {
        $this->requireRecord( $packageName )->forceFill( [ 'uninstalled_at' => Carbon::now() ] )->save();
        $this->flush();
    }

    /**
     * Clears a satellite's uninstalled mark so it re-attaches on the next boot.
     *
     * @since 1.0.0
     *
     * @param  string  $packageName  Composer package name.
     *
     * @throws RuntimeException When the satellite has no row.
     *
     * @return void
     */
    public function markReinstalled( string $packageName ): void
    {
        $this->requireRecord( $packageName )->forceFill( [ 'uninstalled_at' => null ] )->save();
        $this->flush();
    }

    /**
     * Whether an uninstalled satellite may take `$key` out of the product
     * type registry. A descriptor can list any key, so never let it switch
     * off an engine type (`simple`, `digital`, …) or a key another active
     * satellite also declares — the listing is a claim, not proof of
     * ownership.
     *
     * @since 1.0.0
     *
     * @param  SatelliteDescriptor  $descriptor  The uninstalled satellite.
     * @param  string               $key         Product type key it declares.
     * @param  ProductTypeRegistry  $types       Product type registry.
     *
     * @return bool
     */
    protected function ownsProductType( SatelliteDescriptor $descriptor, string $key, ProductTypeRegistry $types ): bool
    {
        if ( ! $types->has( $key ) || str_starts_with( $types->get( $key )::class, 'ArtisanPackUI\\Ecommerce\\' ) ) {
            return false;
        }

        foreach ( $this->descriptors as $package => $other ) {
            if ( $package !== $descriptor->packageName && in_array( $key, $other->productTypes, true ) && $this->isActive( $package ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Upserts one descriptor.
     *
     * @since 1.0.0
     *
     * @param  SatelliteDescriptor  $descriptor  Descriptor.
     *
     * @return Satellite
     */
    protected function persist( SatelliteDescriptor $descriptor ): Satellite
    {
        $row = $this->record( $descriptor->packageName ) ?? new Satellite( [
            'package_name'  => $descriptor->packageName,
            'registered_at' => Carbon::now(),
        ] );

        // A recorded verification covers one release only: a new version
        // has to be verified again before it counts as verified.
        if ( $row->exists && $row->version !== $descriptor->version ) {
            $row->verified_report_hash = null;
        }

        $row->fill( $descriptor->toRow() )->save();

        return $row;
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $packageName  Composer package name.
     *
     * @throws RuntimeException When the satellite has no row.
     *
     * @return Satellite
     */
    protected function requireRecord( string $packageName ): Satellite
    {
        return $this->record( $packageName )
            ?? throw new RuntimeException( sprintf( 'No satellite registered under "%s".', $packageName ) );
    }
}
