<?php

/**
 * ReplayableResult contract.
 *
 * A value an {@see \ArtisanPackUI\Ecommerce\Support\IdempotentAction} can
 * store and hand back on a replay. Models inside the array are stored as
 * references and re-fetched, so a replay returns current data.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface ReplayableResult
{
    /**
     * The value as an array of scalars, arrays, and models.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toReplay(): array;

    /**
     * Rebuilds the value from {@see self::toReplay()}'s output.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $data  Stored data, models re-fetched.
     *
     * @return static
     */
    public static function fromReplay( array $data ): static;
}
