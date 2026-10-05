<?php

/**
 * FulfillmentAllocationStrategyRegistry.
 *
 * Runtime registry of {@see \ArtisanPackUI\Ecommerce\Contracts\FulfillmentAllocationStrategy}
 * implementations, keyed by strategy key. Bound as a singleton in
 * {@see \ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider}.
 *
 * Engine spec §4.6.
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

use ArtisanPackUI\Ecommerce\Contracts\FulfillmentAllocationStrategy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Runtime registry for {@see FulfillmentAllocationStrategy} implementations.
 *
 * Registration accepts a class name (resolved from the container on first
 * lookup) or a pre-built instance. Double-registration throws in
 * `local` + `testing`, warns via `Log::warning` in every other environment
 * so satellite conflicts don't take down production traffic. This mirrors
 * {@see CurrencyRateProviderRegistry} — an allocation lookup for an
 * unregistered key is a misconfiguration that must surface immediately,
 * not silently degrade to a fallback that swallows shipping and tax.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class FulfillmentAllocationStrategyRegistry
{
    /**
     * Registered entries keyed by strategy key.
     *
     * @since 1.0.0
     *
     * @var array<string, array{entry: class-string<FulfillmentAllocationStrategy>|FulfillmentAllocationStrategy, meta: array<string, mixed>}>
     */
    private array $entries = [];

    /**
     * Resolved instance cache — populated lazily on first {@see self::get()}.
     *
     * @since 1.0.0
     *
     * @var array<string, FulfillmentAllocationStrategy>
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
     * Registers an allocation strategy under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string                                                                    $key    Registry key (matches settings value).
     * @param  class-string<FulfillmentAllocationStrategy>|FulfillmentAllocationStrategy  $entry  Instance or class name.
     * @param  array<string, mixed>                                                      $meta   Optional metadata.
     *
     * @throws InvalidArgumentException When `$key` is empty, `$entry` is not a FulfillmentAllocationStrategy, or the key collides in `local`/`testing`.
     *
     * @return void
     */
    public function register( string $key, FulfillmentAllocationStrategy|string $entry, array $meta = [] ): void
    {
        if ( '' === trim( $key ) ) {
            throw new InvalidArgumentException( 'FulfillmentAllocationStrategy registry key must not be empty.' );
        }

        if ( is_string( $entry ) ) {
            if ( ! class_exists( $entry ) || ! is_subclass_of( $entry, FulfillmentAllocationStrategy::class ) ) {
                throw new InvalidArgumentException(
                    sprintf( 'FulfillmentAllocationStrategy entry "%s" must implement %s.', $entry, FulfillmentAllocationStrategy::class ),
                );
            }
        }

        if ( isset( $this->entries[ $key ] ) ) {
            $message = sprintf( 'FulfillmentAllocationStrategy "%s" is already registered; the new entry overwrites the previous one.', $key );

            if ( in_array( $this->container->environment(), [ 'local', 'testing' ], true ) ) {
                throw new InvalidArgumentException( $message );
            }

            Log::warning( $message );
        }

        $this->entries[ $key ]  = [ 'entry' => $entry, 'meta' => $meta ];
        unset( $this->resolved[ $key ] );
    }

    /**
     * Whether a strategy is registered under `$key`.
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
     * Resolves the {@see FulfillmentAllocationStrategy} registered under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Registry key.
     *
     * @throws RuntimeException When no strategy is registered under `$key`.
     *
     * @return FulfillmentAllocationStrategy
     */
    public function get( string $key ): FulfillmentAllocationStrategy
    {
        if ( ! isset( $this->entries[ $key ] ) ) {
            throw new RuntimeException(
                sprintf( 'No FulfillmentAllocationStrategy registered under "%s".', $key ),
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
     * All registered strategies, resolved eagerly and keyed by registry key.
     *
     * @since 1.0.0
     *
     * @return array<string, FulfillmentAllocationStrategy>
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
     * Resolves the {@see FulfillmentAllocationStrategy} named by the active
     * settings key (`artisanpack.ecommerce.fulfillment.allocation_strategy`).
     *
     * @since 1.0.0
     *
     * @throws RuntimeException When the configured strategy is not registered.
     *
     * @return FulfillmentAllocationStrategy
     */
    public function active(): FulfillmentAllocationStrategy
    {
        /** @var string $key */
        $key = $this->container->make( 'config' )->get(
            'artisanpack.ecommerce.fulfillment.allocation_strategy',
            'proportional-by-line-total',
        );

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
