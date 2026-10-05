<?php

/**
 * StoreTimezone.
 *
 * The store's time zone: `artisanpack.ecommerce.timezone` when set,
 * otherwise the application time zone. Reports bucket by it and dates shown
 * to shoppers and staff are displayed in it.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class StoreTimezone
{
    /**
     * The store time zone identifier.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function name(): string
    {
        $timezone = config( 'artisanpack.ecommerce.timezone' );

        return is_string( $timezone ) && '' !== $timezone ? $timezone : (string) ( config( 'app.timezone' ) ?: 'UTC' );
    }
}
