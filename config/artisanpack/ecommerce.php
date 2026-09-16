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
    | Fulfillment
    |--------------------------------------------------------------------------
    |
    | `allocation_strategy` — Key of the FulfillmentAllocationStrategy used to
    |                          split an order's shipping/tax totals across its
    |                          line items (engine spec §4.6, parent plan §16.7).
    |                          Ships with `proportional-by-line-total`.
    |
    */

    'fulfillment' => [
        'allocation_strategy' => env(
            'ECOMMERCE_ALLOCATION_STRATEGY',
            'proportional-by-line-total',
        ),
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
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | `default_ttl_hours`  — Baseline TTL applied to a stored idempotency
    |                        record (engine spec §7.5 / §11.2, default 24h).
    |
    | `ttls`               — Per-endpoint overrides, keyed by the resolved
    |                        `endpoint_key` (route name or normalized
    |                        `{method}:{path}`). Value is TTL in hours.
    |
    | `wait_ms`            — Maximum time (milliseconds) the middleware will
    |                        wait on an in-flight duplicate before returning
    |                        409 (engine spec §11.2, default 8000ms).
    |
    | `poll_ms`            — Interval (milliseconds) between in-flight-lock
    |                        poll attempts while waiting for `wait_ms`.
    |
    | `problem_base_url`   — Base URL used to construct problem+json `type`
    |                        URIs for missing-header / conflict responses.
    |
    */

    'idempotency' => [
        'default_ttl_hours' => (int) env( 'ECOMMERCE_IDEMPOTENCY_TTL_HOURS', 24 ),
        'ttls'              => [],
        'wait_ms'           => (int) env( 'ECOMMERCE_IDEMPOTENCY_WAIT_MS', 8_000 ),
        'poll_ms'           => (int) env( 'ECOMMERCE_IDEMPOTENCY_POLL_MS', 100 ),
        'problem_base_url'  => env(
            'ECOMMERCE_PROBLEM_BASE_URL',
            'https://docs.artisanpack-ui.dev/ecommerce/problems',
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Named rate-limit policies registered against Laravel's `RateLimiter`
    | facade in `EcommerceServiceProvider::registerRateLimiters()`. Each
    | policy maps to one or more `Limit` objects; compound policies (e.g.
    | `checkout.finalize`) have separate per-IP and per-subject buckets.
    |
    | Store owners override the shipped defaults per policy without
    | overwriting the whole map — any key left absent falls back to the
    | value baked in below. Values are always in requests per window; the
    | window (per-minute vs. per-hour) is fixed by the engine spec §11.3.
    |
    | `problem_base_url` — Base URL used to build problem+json `type` URIs
    |                      when a caller exceeds their allowance.
    |
    */

    'rate_limits' => [

        'catalog.read' => [
            'per_ip' => (int) env( 'ECOMMERCE_RATE_CATALOG_READ_PER_IP', 300 ),
        ],

        'cart.mutate' => [
            'per_cart' => (int) env( 'ECOMMERCE_RATE_CART_MUTATE_PER_CART', 60 ),
        ],

        'checkout.finalize' => [
            'per_ip'   => (int) env( 'ECOMMERCE_RATE_CHECKOUT_FINALIZE_PER_IP', 6 ),
            'per_cart' => (int) env( 'ECOMMERCE_RATE_CHECKOUT_FINALIZE_PER_CART', 12 ),
        ],

        'coupon.attempt' => [
            'per_cart' => (int) env( 'ECOMMERCE_RATE_COUPON_ATTEMPT_PER_CART', 10 ),
            'per_ip'   => (int) env( 'ECOMMERCE_RATE_COUPON_ATTEMPT_PER_IP', 30 ),
        ],

        'login' => [
            'per_ip'    => (int) env( 'ECOMMERCE_RATE_LOGIN_PER_IP', 5 ),
            'per_email' => (int) env( 'ECOMMERCE_RATE_LOGIN_PER_EMAIL', 20 ),
        ],

        'review.submit' => [
            'per_customer' => (int) env( 'ECOMMERCE_RATE_REVIEW_SUBMIT_PER_CUSTOMER', 3 ),
            'per_ip'       => (int) env( 'ECOMMERCE_RATE_REVIEW_SUBMIT_PER_IP', 10 ),
        ],

        'license.validate' => [
            'per_license' => (int) env( 'ECOMMERCE_RATE_LICENSE_VALIDATE_PER_LICENSE', 60 ),
            'per_ip'      => (int) env( 'ECOMMERCE_RATE_LICENSE_VALIDATE_PER_IP', 600 ),
        ],

        'webhook.inbound' => [
            'per_provider' => (int) env( 'ECOMMERCE_RATE_WEBHOOK_INBOUND_PER_PROVIDER', 1_000 ),
        ],

        'admin.mutate' => [
            'per_user' => (int) env( 'ECOMMERCE_RATE_ADMIN_MUTATE_PER_USER', 120 ),
        ],

        'problem_base_url' => env(
            'ECOMMERCE_RATE_LIMIT_PROBLEM_BASE_URL',
            'https://docs.artisanpack-ui.dev/ecommerce/problems',
        ),

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
