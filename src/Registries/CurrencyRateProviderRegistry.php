<?php

/**
 * CurrencyRateProviderRegistry.
 *
 * Runtime registry of {@see \ArtisanPackUI\Ecommerce\Contracts\CurrencyRateProvider}
 * implementations, keyed by provider key. Bound as a singleton in
 * {@see \ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider}.
 *
 * Engine spec §5 (row 6).
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

use ArtisanPackUI\Ecommerce\Contracts\CurrencyRateProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Runtime registry for {@see CurrencyRateProvider} implementations.
 *
 * Registration accepts a class name (resolved from the container on first
 * lookup) or a pre-built instance. Double-registration throws in
 * `local` + `testing`, warns via `Log::warning` in every other environment
 * so satellite conflicts don't take down production traffic.
 *
 * Unlike {@see ProductTypeRegistry}, this registry has no placeholder for
 * unknown keys: an FX-rate lookup for an unregistered provider is a
 * misconfiguration that must surface immediately, not silently degrade to
 * a fallback rate of `0`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CurrencyRateProviderRegistry
{
    /**
     * Registered entries keyed by provider key.
     *
     * @since 1.0.0
     *
     * @var array<string, array{entry: class-string<CurrencyRateProvider>|CurrencyRateProvider, meta: array<string, mixed>}>
     */
    private array $entries = [];

    /**
     * Resolved instance cache — populated lazily on first {@see self::get()}.
     *
     * @since 1.0.0
     *
     * @var array<string, CurrencyRateProvider>
     */
    private array $resolved = [];

    /**
     * Creates a registry bound to the given application for lazy resolution.
     *
     * @since 1.0.0
     *
     * @param  Application  $container  Application used to resolve class-name entries.
     */
    public function __construct( private readonly Application $container )
    {
    }

    /**
     * Registers a rate provider under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string                                                 $key    Registry key (matches settings value).
     * @param  class-string<CurrencyRateProvider>|CurrencyRateProvider  $entry  Instance or class name.
     * @param  array<string, mixed>                                   $meta   Optional metadata.
     *
     * @throws InvalidArgumentException When `$key` is empty, `$entry` is not a CurrencyRateProvider, or the key collides in `local`/`testing`.
     *
     * @return void
     */
    public function register( string $key, CurrencyRateProvider|string $entry, array $meta = [] ): void
    {
        if ( '' === trim( $key ) ) {
            throw new InvalidArgumentException( 'CurrencyRateProvider registry key must not be empty.' );
        }

        if ( is_string( $entry ) ) {
            if ( ! class_exists( $entry ) || ! is_subclass_of( $entry, CurrencyRateProvider::class ) ) {
                throw new InvalidArgumentException(
                    sprintf( 'CurrencyRateProvider entry "%s" must implement %s.', $entry, CurrencyRateProvider::class ),
                );
            }
        }

        if ( isset( $this->entries[ $key ] ) ) {
            $message = sprintf( 'CurrencyRateProvider "%s" is already registered; the new entry overwrites the previous one.', $key );

            if ( in_array( $this->container->environment(), [ 'local', 'testing' ], true ) ) {
                throw new InvalidArgumentException( $message );
            }

            Log::warning( $message );
        }

        $this->entries[ $key ]  = [ 'entry' => $entry, 'meta' => $meta ];
        unset( $this->resolved[ $key ] );
    }

    /**
     * Whether a rate provider is registered under `$key`.
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
     * Resolves the {@see CurrencyRateProvider} registered under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Registry key.
     *
     * @throws RuntimeException When no provider is registered under `$key`.
     *
     * @return CurrencyRateProvider
     */
    public function get( string $key ): CurrencyRateProvider
    {
        if ( ! isset( $this->entries[ $key ] ) ) {
            throw new RuntimeException(
                sprintf( 'No CurrencyRateProvider registered under "%s".', $key ),
            );
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
     * All registered rate providers, resolved eagerly and keyed by registry key.
     *
     * @since 1.0.0
     *
     * @return array<string, CurrencyRateProvider>
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
     * Resolves the {@see CurrencyRateProvider} named by the active settings
     * key (`artisanpack.ecommerce.currency.provider`).
     *
     * @since 1.0.0
     *
     * @throws RuntimeException When the configured provider is not registered.
     *
     * @return CurrencyRateProvider
     */
    public function active(): CurrencyRateProvider
    {
        /** @var string $key */
        $key = $this->container->make( 'config' )->get( 'artisanpack.ecommerce.currency.provider', 'config' );

        return $this->get( $key );
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
