<?php

/**
 * ProductTypeRegistry.
 *
 * Runtime registry of {@see \ArtisanPackUI\Ecommerce\Contracts\ProductType}
 * implementations, keyed by `products.type`. Bound as a singleton in
 * {@see \ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider}.
 *
 * Engine spec §5 (Registries).
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

use ArtisanPackUI\Ecommerce\Contracts\ProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\MissingProductType;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Runtime registry for {@see ProductType} implementations.
 *
 * Registration accepts a class name (resolved from the container on first
 * lookup) or a pre-built instance. Double-registration throws in
 * `local` + `testing`, warns via `Log::warning` in every other environment
 * so satellite conflicts don't take down production traffic.
 *
 * Lookup for an unknown key returns a {@see MissingProductType} carrying
 * the requested key — the placeholder pattern from spec §16.6 so an
 * orphaned `products.type` row never fatals the storefront when a
 * satellite is uninstalled with rows still in the wild.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductTypeRegistry
{
    /**
     * Registered entries keyed by product type.
     *
     * @since 1.0.0
     *
     * @var array<string, array{entry: class-string<ProductType>|ProductType, meta: array<string, mixed>}>
     */
    private array $entries = [];

    /**
     * Resolved instance cache — populated lazily on first {@see self::get()}.
     *
     * @since 1.0.0
     *
     * @var array<string, ProductType>
     */
    private array $resolved = [];

    /**
     * Creates a registry bound to the given application for lazy resolution.
     *
     * The `Application` contract is required (rather than the broader
     * `Container` contract) because the duplicate-key policy branches on
     * `environment()`, which the container interface does not carry.
     *
     * @since 1.0.0
     *
     * @param  Application  $container  Application used to resolve class-name entries.
     */
    public function __construct( private readonly Application $container )
    {
    }

    /**
     * Registers a product type under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string                                 $key    Registry key (matches `products.type`).
     * @param  class-string<ProductType>|ProductType  $entry  Instance or class name.
     * @param  array<string, mixed>                   $meta   Optional metadata (label, icon, group).
     *
     * @throws InvalidArgumentException When `$key` is empty, `$entry` is not a ProductType, or the key collides in `local`/`testing`.
     *
     * @return void
     */
    public function register( string $key, ProductType|string $entry, array $meta = [] ): void
    {
        if ( '' === trim( $key ) ) {
            throw new InvalidArgumentException( 'ProductType registry key must not be empty.' );
        }

        if ( is_string( $entry ) ) {
            if ( ! class_exists( $entry ) || ! is_subclass_of( $entry, ProductType::class ) ) {
                throw new InvalidArgumentException(
                    sprintf( 'ProductType entry "%s" must implement %s.', $entry, ProductType::class ),
                );
            }
        }

        if ( isset( $this->entries[ $key ] ) ) {
            $message = sprintf( 'ProductType "%s" is already registered; the new entry overwrites the previous one.', $key );

            if ( in_array( $this->container->environment(), [ 'local', 'testing' ], true ) ) {
                throw new InvalidArgumentException( $message );
            }

            Log::warning( $message );
        }

        $this->entries[ $key ]  = [ 'entry' => $entry, 'meta' => $meta ];
        unset( $this->resolved[ $key ] );
    }

    /**
     * Whether a product type is registered under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Registry key.
     *
     * @return bool
     */
    public function has( string $key ): bool
    {
        return isset( $this->entries[ $key ] );
    }

    /**
     * Resolves the {@see ProductType} for `$key`, or a {@see MissingProductType}
     * carrying `$key` when nothing is registered.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Registry key.
     *
     * @return ProductType
     */
    public function get( string $key ): ProductType
    {
        if ( ! isset( $this->entries[ $key ] ) ) {
            return new MissingProductType( $key );
        }

        if ( isset( $this->resolved[ $key ] ) ) {
            return $this->resolved[ $key ];
        }

        $entry = $this->entries[ $key ][ 'entry' ];

        return $this->resolved[ $key ] = is_string( $entry )
            ? $this->container->make( $entry )
            : $entry;
    }

    /**
     * Returns the metadata array registered alongside `$key`.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Registry key.
     *
     * @return array<string, mixed>
     */
    public function meta( string $key ): array
    {
        return $this->entries[ $key ][ 'meta' ] ?? [];
    }

    /**
     * All registered product types, resolved eagerly and keyed by registry key.
     *
     * @since 1.0.0
     *
     * @return array<string, ProductType>
     */
    public function all(): array
    {
        $out = [];

        foreach ( array_keys( $this->entries ) as $key ) {
            $out[ $key ] = $this->get( $key );
        }

        return $out;
    }

    /**
     * Registry keys, in registration order.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys( $this->entries );
    }

    /**
     * Removes `$key` from the registry. No-op when the key isn't registered.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Registry key.
     *
     * @return void
     */
    public function forget( string $key ): void
    {
        unset( $this->entries[ $key ], $this->resolved[ $key ] );
    }
}
