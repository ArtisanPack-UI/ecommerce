<?php

/**
 * FraudProviderRegistry.
 *
 * Runtime registry of {@see \ArtisanPackUI\Ecommerce\Contracts\FraudProvider}
 * implementations, keyed by provider key. Bound as a singleton in
 * {@see \ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider}.
 *
 * The orchestrator resolves the active provider(s) from
 * `artisanpack.ecommerce.fraud.provider`. A comma-separated value runs
 * the providers in `chain` mode (engine spec §5) — most-conservative
 * verdict wins; chain composition itself is applied by the caller of
 * {@see self::get()}, not the registry.
 *
 * Engine spec §5 (row 12).
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

use ArtisanPackUI\Ecommerce\Contracts\FraudProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Runtime registry for {@see FraudProvider} implementations.
 *
 * Registration accepts a class name (resolved from the container on first
 * lookup) or a pre-built instance. Double-registration throws in `local`
 * + `testing`, warns via `Log::warning` in every other environment so
 * satellite conflicts don't take down production traffic.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class FraudProviderRegistry
{
    /**
     * Registered entries keyed by provider key.
     *
     * @since 1.0.0
     *
     * @var array<string, array{entry: class-string<FraudProvider>|FraudProvider, meta: array<string, mixed>}>
     */
    private array $entries = [];

    /**
     * Resolved instance cache — populated lazily on first {@see self::get()}.
     *
     * @since 1.0.0
     *
     * @var array<string, FraudProvider>
     */
    private array $resolved = [];

    /**
     * @since 1.0.0
     *
     * @param  Application  $container  Application used to resolve class-name entries.
     */
    public function __construct( private readonly Application $container )
    {
    }

    /**
     * Registers a fraud provider under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string                                       $key
     * @param  class-string<FraudProvider>|FraudProvider    $entry
     * @param  array<string, mixed>                         $meta
     *
     * @throws InvalidArgumentException When `$key` is empty, `$entry` is not a FraudProvider, or the key collides in `local`/`testing`.
     *
     * @return void
     */
    public function register( string $key, FraudProvider|string $entry, array $meta = [] ): void
    {
        if ( '' === trim( $key ) ) {
            throw new InvalidArgumentException( 'FraudProvider registry key must not be empty.' );
        }

        if ( is_string( $entry ) ) {
            if ( ! class_exists( $entry ) || ! is_subclass_of( $entry, FraudProvider::class ) ) {
                throw new InvalidArgumentException(
                    sprintf( 'FraudProvider entry "%s" must implement %s.', $entry, FraudProvider::class ),
                );
            }
        }

        if ( isset( $this->entries[ $key ] ) ) {
            $message = sprintf( 'FraudProvider "%s" is already registered; the new entry overwrites the previous one.', $key );

            if ( in_array( $this->container->environment(), [ 'local', 'testing' ], true ) ) {
                throw new InvalidArgumentException( $message );
            }

            Log::warning( $message );
        }

        $this->entries[ $key ] = [ 'entry' => $entry, 'meta' => $meta ];
        unset( $this->resolved[ $key ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $key
     *
     * @return bool
     */
    public function has( string $key ): bool
    {
        return isset( $this->entries[ $key ] );
    }

    /**
     * Resolves the {@see FraudProvider} registered under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string  $key
     *
     * @throws RuntimeException When no provider is registered under `$key`, or the resolved provider's self-reported key does not match.
     *
     * @return FraudProvider
     */
    public function get( string $key ): FraudProvider
    {
        if ( ! isset( $this->entries[ $key ] ) ) {
            throw new RuntimeException(
                sprintf( 'No FraudProvider registered under "%s".', $key ),
            );
        }

        if ( isset( $this->resolved[ $key ] ) ) {
            return $this->resolved[ $key ];
        }

        $entry = $this->entries[ $key ][ 'entry' ];

        $provider = is_string( $entry )
            ? $this->container->make( $entry )
            : $entry;

        if ( $provider->key() !== $key ) {
            throw new RuntimeException( sprintf(
                'FraudProvider registered under "%s" reports its own key as "%s"; refusing to resolve it to avoid mis-attribution of fraud decisions.',
                $key,
                $provider->key(),
            ) );
        }

        return $this->resolved[ $key ] = $provider;
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $key
     *
     * @return array<string, mixed>
     */
    public function meta( string $key ): array
    {
        return $this->entries[ $key ][ 'meta' ] ?? [];
    }

    /**
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys( $this->entries );
    }
}
