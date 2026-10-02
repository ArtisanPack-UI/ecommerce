<?php

/**
 * Ecommerce helper functions.
 *
 * This file contains global helper functions for the Ecommerce package.
 * Add your custom helper functions below.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */

use ArtisanPackUI\Ecommerce\Ecommerce;

if ( ! function_exists( 'ecommerce' ) ) {
    /**
     * Get the Ecommerce instance.
     *
     * @since 1.0.0
     *
     * @return Ecommerce
     */
    function ecommerce(): Ecommerce
    {
        return app( 'ecommerce' );
    }
}

// Add your custom helper functions below

if ( ! function_exists( 'ecommerceSetting' ) ) {
    /**
     * The current value of an allow-listed store setting: the value an
     * admin stored, otherwise config, otherwise `$default`.
     *
     * @since 1.0.0
     *
     * @param  string  $key      Setting key, e.g. `tax.provider`.
     * @param  mixed   $default  Fallback.
     *
     * @return mixed
     */
    function ecommerceSetting( string $key, mixed $default = null ): mixed
    {
        return app( ArtisanPackUI\Ecommerce\Settings\SettingsRepository::class )->get( $key, $default );
    }
}
