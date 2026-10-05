<?php

/**
 * NotificationChannelRegistry.
 *
 * Discoverability wrapper around the Laravel notification channels the
 * store can deliver through (engine spec §5 row 13, engine issue #150).
 * It does not send anything: admins read it to offer channel choices
 * (notification preferences, template channels), and satellites add the
 * channels they ship:
 *
 * ```php
 * app( NotificationChannelRegistry::class )->register( 'sms', VonageSmsChannel::class, [ 'label' => fn (): string => __( 'Text message' ) ] );
 * ```
 *
 * The core registers `mail` and `database`. `driver` is whatever Laravel's
 * `via()` accepts: a built-in channel name or a channel class.
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

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NotificationChannelRegistry
{
    /**
     * Registered channels, keyed by channel key.
     *
     * @since 1.0.0
     *
     * @var array<string, array{driver: string, meta: array<string, mixed>}>
     */
    private array $channels = [];

    /**
     * @since 1.0.0
     *
     * @param  Application  $container  Application (environment).
     */
    public function __construct( private readonly Application $container )
    {
    }

    /**
     * Registers a channel.
     *
     * @since 1.0.0
     *
     * @param  string                $key     Channel key (`mail`, `sms`, …).
     * @param  string|null           $driver  Laravel channel name or class; defaults to `$key`.
     * @param  array<string, mixed>  $meta    Display metadata: `label` (string or closure), `provided_by`.
     *
     * @throws InvalidArgumentException When `$key` is empty, or collides in `local`/`testing`.
     *
     * @return void
     */
    public function register( string $key, ?string $driver = null, array $meta = [] ): void
    {
        $key = trim( $key );

        if ( '' === $key ) {
            throw new InvalidArgumentException( 'Notification channel key must not be empty.' );
        }

        if ( isset( $this->channels[ $key ] ) ) {
            $message = sprintf( 'Notification channel "%s" is already registered; the new entry overwrites the previous one.', $key );

            if ( in_array( $this->container->environment(), [ 'local', 'testing' ], true ) ) {
                throw new InvalidArgumentException( $message );
            }

            Log::warning( $message );
        }

        $this->channels[ $key ] = [ 'driver' => null === $driver || '' === trim( $driver ) ? $key : $driver, 'meta' => $meta ];
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $key  Channel key.
     *
     * @return bool
     */
    public function has( string $key ): bool
    {
        return isset( $this->channels[ $key ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $key  Channel key.
     *
     * @throws RuntimeException When nothing is registered under `$key`.
     *
     * @return array{key: string, label: string, driver: string, provided_by: string}
     */
    public function get( string $key ): array
    {
        if ( ! isset( $this->channels[ $key ] ) ) {
            throw new RuntimeException( sprintf( 'No notification channel registered under "%s".', $key ) );
        }

        $meta  = $this->channels[ $key ]['meta'];
        $label = $meta['label'] ?? $key;

        return [
            'key'         => $key,
            'label'       => (string) ( $label instanceof Closure ? $label() : $label ),
            'driver'      => $this->channels[ $key ]['driver'],
            'provided_by' => (string) ( $meta['provided_by'] ?? 'ecommerce' ),
        ];
    }

    /**
     * Every channel, in registration order.
     *
     * @since 1.0.0
     *
     * @return array<string, array{key: string, label: string, driver: string, provided_by: string}>
     */
    public function all(): array
    {
        $all = [];

        foreach ( $this->keys() as $key ) {
            $all[ $key ] = $this->get( $key );
        }

        return $all;
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $key  Channel key.
     *
     * @return array<string, mixed>
     */
    public function meta( string $key ): array
    {
        return $this->channels[ $key ]['meta'] ?? [];
    }

    /**
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys( $this->channels );
    }
}
