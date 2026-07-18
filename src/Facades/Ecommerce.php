<?php

/**
 * Ecommerce Facade.
 *
 * Provides static access to the Ecommerce class.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Ecommerce Facade.
 *
 * @see \ArtisanPackUI\Ecommerce\Ecommerce
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class Ecommerce extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'ecommerce';
    }
}
