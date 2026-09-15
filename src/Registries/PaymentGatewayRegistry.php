<?php

/**
 * PaymentGatewayRegistry.
 *
 * Runtime registry of {@see \ArtisanPackUI\Ecommerce\Contracts\PaymentGateway}
 * implementations, keyed by gateway key. Bound as a singleton in
 * {@see \ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider}.
 *
 * The service layer resolves the gateway an {@see \ArtisanPackUI\Ecommerce\Models\Order}
 * was placed through via its `payment_gateway_key` column. Satellites (Stripe,
 * PayPal, offline/manual) register their gateway from their own provider
 * `boot()` and the engine never has to know about them at compile time.
 *
 * Engine spec §5.
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

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Runtime registry for {@see PaymentGateway} implementations.
 *
 * Registration accepts a class name (resolved from the container on first
 * lookup) or a pre-built instance. Double-registration throws in `local` +
 * `testing`, warns via `Log::warning` in every other environment so
 * satellite conflicts don't take down production traffic.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PaymentGatewayRegistry
{
    /**
     * Registered entries keyed by gateway key.
     *
     * @since 1.0.0
     *
     * @var array<string, array{entry: class-string<PaymentGateway>|PaymentGateway, meta: array<string, mixed>}>
     */
    private array $entries = [];

    /**
     * Resolved instance cache — populated lazily on first {@see self::get()}.
     *
     * @since 1.0.0
     *
     * @var array<string, PaymentGateway>
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
     * Registers a gateway under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string                                        $key    Registry key (matches `orders.payment_gateway_key`).
     * @param  class-string<PaymentGateway>|PaymentGateway   $entry  Instance or class name.
     * @param  array<string, mixed>                          $meta   Optional metadata.
     *
     * @throws InvalidArgumentException When `$key` is empty, `$entry` is not a PaymentGateway, or the key collides in `local`/`testing`.
     *
     * @return void
     */
    public function register( string $key, PaymentGateway|string $entry, array $meta = [] ): void
    {
        if ( '' === trim( $key ) ) {
            throw new InvalidArgumentException( 'PaymentGateway registry key must not be empty.' );
        }

        if ( is_string( $entry ) ) {
            if ( ! class_exists( $entry ) || ! is_subclass_of( $entry, PaymentGateway::class ) ) {
                throw new InvalidArgumentException(
                    sprintf( 'PaymentGateway entry "%s" must implement %s.', $entry, PaymentGateway::class ),
                );
            }
        }

        if ( isset( $this->entries[ $key ] ) ) {
            $message = sprintf( 'PaymentGateway "%s" is already registered; the new entry overwrites the previous one.', $key );

            if ( in_array( $this->container->environment(), [ 'local', 'testing' ], true ) ) {
                throw new InvalidArgumentException( $message );
            }

            Log::warning( $message );
        }

        $this->entries[ $key ]  = [ 'entry' => $entry, 'meta' => $meta ];
        unset( $this->resolved[ $key ] );
    }

    /**
     * Whether a gateway is registered under `$key`.
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
     * Resolves the {@see PaymentGateway} registered under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Registry key.
     *
     * @throws RuntimeException When no gateway is registered under `$key`.
     *
     * @return PaymentGateway
     */
    public function get( string $key ): PaymentGateway
    {
        if ( ! isset( $this->entries[ $key ] ) ) {
            throw new RuntimeException(
                sprintf( 'No PaymentGateway registered under "%s".', $key ),
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
}
