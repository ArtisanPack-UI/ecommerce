<?php

/**
 * SatelliteDescriptor.
 *
 * What a satellite declares about itself when it registers with the
 * {@see \ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry} (parent
 * plan §16.6, engine spec §3.32).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Satellites;

use ArtisanPackUI\Ecommerce\Contracts\SatelliteUninstaller;
use ArtisanPackUI\Ecommerce\Models\Satellite;
use InvalidArgumentException;

/**
 * Immutable satellite descriptor.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class SatelliteDescriptor
{
    /**
     * @since 1.0.0
     *
     * @param  string                                  $packageName     Composer package name (`vendor/package`).
     * @param  string                                  $version         Installed version.
     * @param  string|null                             $label           Human-readable name.
     * @param  array<int, string>                      $migrationPaths  Directories holding the satellite's migrations (absolute, or relative to the base path).
     * @param  array<int, string>                      $configKeys      Config keys the satellite publishes (`ecommerce-subscriptions`, …).
     * @param  array<int, string>                      $metaNamespaces  `orders.meta` / `cart.meta` namespaces it owns (`orders.meta.subscription`).
     * @param  array<int, string>                      $tables          Tables the satellite creates.
     * @param  array<string, array<int, string>>       $columns         Columns it adds to tables it does not own, keyed by table.
     * @param  array<int, string>                      $productTypes    Product type keys it registers.
     * @param  class-string<SatelliteUninstaller>|null $uninstaller     Uninstaller class.
     *
     * @throws InvalidArgumentException When the package name is not `vendor/package` or the version is empty.
     */
    public function __construct(
        public readonly string $packageName,
        public readonly string $version,
        public readonly ?string $label = null,
        public readonly array $migrationPaths = [],
        public readonly array $configKeys = [],
        public readonly array $metaNamespaces = [],
        public readonly array $tables = [],
        public readonly array $columns = [],
        public readonly array $productTypes = [],
        public readonly ?string $uninstaller = null,
    ) {
        if ( 1 !== preg_match( '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $packageName ) ) {
            throw new InvalidArgumentException( sprintf( 'Satellite package name "%s" must be a Composer package name (vendor/package).', $packageName ) );
        }

        if ( '' === trim( $version ) ) {
            throw new InvalidArgumentException( sprintf( 'Satellite "%s" must declare its version.', $packageName ) );
        }
    }

    /**
     * Builds a descriptor from an array using the snake_case keys a
     * satellite passes to `SatelliteRegistry::register()`.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $data  Descriptor data.
     *
     * @return self
     */
    public static function fromArray( array $data ): self
    {
        return new self(
            packageName: (string) ( $data['package_name'] ?? '' ),
            version: (string) ( $data['version'] ?? '' ),
            label: isset( $data['label'] ) ? (string) $data['label'] : null,
            migrationPaths: self::strings( $data['migration_paths'] ?? [] ),
            configKeys: self::strings( $data['config_keys'] ?? [] ),
            metaNamespaces: self::strings( $data['meta_namespaces'] ?? [] ),
            tables: self::strings( $data['tables'] ?? [] ),
            columns: self::columnMap( $data['columns'] ?? [] ),
            productTypes: self::strings( $data['product_types'] ?? [] ),
            uninstaller: isset( $data['uninstaller'] ) ? (string) $data['uninstaller'] : null,
        );
    }

    /**
     * Rebuilds a descriptor from its persisted row — used when the
     * satellite no longer boots (e.g. after `composer remove`).
     *
     * @since 1.0.0
     *
     * @param  Satellite  $row  Persisted satellite.
     *
     * @return self
     */
    public static function fromModel( Satellite $row ): self
    {
        return new self(
            packageName: $row->package_name,
            version: $row->version,
            label: $row->label,
            migrationPaths: self::strings( $row->migrations_namespace ?? [] ),
            configKeys: self::strings( $row->config_keys ?? [] ),
            metaNamespaces: self::strings( $row->meta_namespaces ?? [] ),
            tables: self::strings( $row->owned_tables ?? [] ),
            columns: self::columnMap( $row->owned_columns ?? [] ),
            productTypes: self::strings( $row->product_types ?? [] ),
            uninstaller: $row->uninstaller,
        );
    }

    /**
     * The attributes persisted to `ecommerce_satellites`.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'label'                => $this->label,
            'version'              => $this->version,
            'migrations_namespace' => $this->migrationPaths,
            'config_keys'          => $this->configKeys,
            'meta_namespaces'      => $this->metaNamespaces,
            'owned_tables'         => $this->tables,
            'owned_columns'        => $this->columns,
            'product_types'        => $this->productTypes,
            'uninstaller'          => $this->uninstaller,
        ];
    }

    /**
     * Display name: the label, falling back to the package name.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function displayName(): string
    {
        return $this->label ?? $this->packageName;
    }

    /**
     * @since 1.0.0
     *
     * @param  mixed  $value  Raw list.
     *
     * @return array<int, string>
     */
    private static function strings( mixed $value ): array
    {
        return array_values( array_unique( array_filter( array_map( 'strval', (array) $value ), static fn ( string $item ): bool => '' !== $item ) ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  mixed  $value  Raw `table => columns` map.
     *
     * @return array<string, array<int, string>>
     */
    private static function columnMap( mixed $value ): array
    {
        $map = [];

        foreach ( (array) $value as $table => $columns ) {
            $columns = self::strings( $columns );

            if ( is_string( $table ) && '' !== $table && [] !== $columns ) {
                $map[ $table ] = $columns;
            }
        }

        return $map;
    }
}
