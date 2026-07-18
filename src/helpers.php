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
