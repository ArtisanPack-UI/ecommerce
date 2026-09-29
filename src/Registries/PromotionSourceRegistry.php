<?php

/**
 * PromotionSourceRegistry.
 *
 * Marker registry of promotion sources — the valid values for
 * `promotions.source_type`. Core registers `automatic` and `coupon`;
 * satellites add `gift-card`, `referral`, `loyalty`, … Sources carry
 * display metadata only; the satellite that owns a source decides when to
 * hand its promotions to the engine.
 *
 * Engine spec §5 row 9.
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

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PromotionSourceRegistry
{
    /**
     * Registered sources: key → meta.
     *
     * @since 1.0.0
     *
     * @var array<string, array<string, mixed>>
     */
    private array $sources = [];

    /**
     * @since 1.0.0
     *
     * @param  Application  $container  Application (for environment checks).
     */
    public function __construct( private readonly Application $container )
    {
    }

    /**
     * Registers a source key.
     *
     * @since 1.0.0
     *
     * @param  string                $key   Source key.
     * @param  array<string, mixed>  $meta  Display metadata (`label`, …).
     *
     * @throws InvalidArgumentException When `$key` is empty or collides in `local`/`testing`.
     *
     * @return void
     */
    public function register( string $key, array $meta = [] ): void
    {
        if ( '' === trim( $key ) ) {
            throw new InvalidArgumentException( 'Promotion source key must not be empty.' );
        }

        if ( isset( $this->sources[ $key ] ) ) {
            $message = sprintf( 'Promotion source "%s" is already registered; the new entry overwrites the previous one.', $key );

            if ( in_array( $this->container->environment(), [ 'local', 'testing' ], true ) ) {
                throw new InvalidArgumentException( $message );
            }

            Log::warning( $message );
        }

        $this->sources[ $key ] = $meta;
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $key  Source key.
     *
     * @return bool
     */
    public function has( string $key ): bool
    {
        return isset( $this->sources[ $key ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $key  Source key.
     *
     * @return array<string, mixed>
     */
    public function meta( string $key ): array
    {
        return $this->sources[ $key ] ?? [];
    }

    /**
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys( $this->sources );
    }
}
