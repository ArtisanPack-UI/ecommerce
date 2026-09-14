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
    | Currency
    |--------------------------------------------------------------------------
    |
    | Configuration for `CurrencyRateProvider` implementations used to convert
    | `product_prices` from the store's base currency into a customer-facing
    | currency when no explicit per-currency row exists.
    |
    | `provider`   — Registry key of the active provider. Must be registered
    |                against `CurrencyRateProviderRegistry`. Core ships
    |                `config` and `frankfurter`.
    |
    | `rates`      — Static rate table consumed by `ConfigRateProvider`. Rates
    |                are stored as integers of `rate * 10^8`, matching the
    |                E8 representation used across the engine (§2.3).
    |                Example:
    |                  'USD' => [
    |                      'EUR' => 92_500_000,   // 1 USD → 0.925 EUR
    |                      'GBP' => 79_000_000,   // 1 USD → 0.79  GBP
    |                  ]
    |
    | `frankfurter` — Config for the Frankfurter-backed provider.
    |
    */

    'currency' => [
        'provider' => env( 'ECOMMERCE_CURRENCY_PROVIDER', 'config' ),

        'rates' => [],

        'frankfurter' => [
            'base_url'  => env( 'ECOMMERCE_FRANKFURTER_URL', 'https://api.frankfurter.dev/v1' ),
            'cache_ttl' => (int) env( 'ECOMMERCE_FRANKFURTER_CACHE_TTL', 86_400 ),
            'timeout'   => (int) env( 'ECOMMERCE_FRANKFURTER_TIMEOUT', 5 ),
        ],
    ],

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
    | Checkout
    |--------------------------------------------------------------------------
    |
    | `reservation_ttl_minutes` — TTL applied to `inventory_reservations` rows
    | created when a customer enters checkout. The
    | `ecommerce:release-expired-reservations` scheduled command sweeps rows
    | past this TTL every minute.
    |
    */

    'checkout' => [
        'reservation_ttl_minutes' => (int) env( 'ECOMMERCE_RESERVATION_TTL_MINUTES', 15 ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Customers
    |--------------------------------------------------------------------------
    |
    | `claim_rate_limit`             — Maximum guest-order claim attempts a
    |                                   single customer may make within
    |                                   `claim_rate_window_minutes` (engine
    |                                   spec §3.22, default: 5).
    |
    | `claim_rate_window_minutes`    — Length of the rate-limit window in
    |                                   minutes (default: 60).
    |
    */

    'customers' => [
        'claim_rate_limit'          => (int) env( 'ECOMMERCE_CLAIM_RATE_LIMIT', 5 ),
        'claim_rate_window_minutes' => (int) env( 'ECOMMERCE_CLAIM_RATE_WINDOW_MINUTES', 60 ),
    ],

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
