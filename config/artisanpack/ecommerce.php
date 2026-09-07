<?php

/**
 * Ecommerce package configuration.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

return [

    /*
    |--------------------------------------------------------------------------
    | Base currency
    |--------------------------------------------------------------------------
    |
    | ISO 4217 code used as the store's base currency for FX conversion and
    | reporting. Individual carts and orders may transact in other currencies.
    |
    */

    'base_currency' => env( 'ECOMMERCE_BASE_CURRENCY', 'USD' ),

    /*
    |--------------------------------------------------------------------------
    | Route prefix
    |--------------------------------------------------------------------------
    |
    | URI prefix used for engine-provided routes (REST + storefront). Satellite
    | packages may register additional routes under their own prefixes.
    |
    */

    'route_prefix' => env( 'ECOMMERCE_ROUTE_PREFIX', 'shop' ),

    /*
    |--------------------------------------------------------------------------
    | API prefix
    |--------------------------------------------------------------------------
    */

    'api_prefix' => env( 'ECOMMERCE_API_PREFIX', 'api/ecommerce' ),

    /*
    |--------------------------------------------------------------------------
    | Feature toggles
    |--------------------------------------------------------------------------
    */

    'features' => [
        'rest'    => true,
        'graphql' => true,
        'scout'   => true,
    ],

];
