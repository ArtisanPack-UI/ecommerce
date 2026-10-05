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
    | Store time zone
    |--------------------------------------------------------------------------
    |
    | IANA time zone the store reports in: report date ranges and day / week /
    | month buckets start at midnight here. `null` uses `app.timezone`.
    |
    | Base currency, time zone, and the other keys the settings registry
    | allow-lists can also be changed from an admin; stored values in
    | `ecommerce_settings` overlay what is set here (see docs/settings.md).
    |
    */

    'timezone' => env( 'ECOMMERCE_TIMEZONE' ),

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
    | `enabled`    — Currencies the store sells in besides the base currency
    |                (comma-separated in ECOMMERCE_CURRENCIES). Storefronts
    |                offer these in a currency switcher; carts can only be
    |                priced in an enabled currency.
    | `cookie`     — Cookie the default CurrencyResolver reads a shopper's
    |                choice from (storefronts without sessions set it).
    | `session_key`— Session key the default CurrencyResolver reads and
    |                remembers the choice under.
    |
    */

    'currency' => [
        'provider' => env( 'ECOMMERCE_CURRENCY_PROVIDER', 'config' ),

        'enabled'     => array_values( array_filter( array_map( 'trim', explode( ',', (string) env( 'ECOMMERCE_CURRENCIES', '' ) ) ) ) ),
        'cookie'      => env( 'ECOMMERCE_CURRENCY_COOKIE', 'ecommerce_currency' ),
        'session_key' => 'ecommerce.currency',

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
    | `label_claim_ttl_minutes` — How long a shipping-label purchase may stay
    |                          claimed before another request may retry it
    |                          (a crashed worker leaves the claim behind).
    |                          Retries reuse the claim key, so carriers that
    |                          honour idempotency keys never double-charge.
    |
    */

    'fulfillment' => [
        'allocation_strategy'     => env(
            'ECOMMERCE_ALLOCATION_STRATEGY',
            'proportional-by-line-total',
        ),
        'label_claim_ttl_minutes' => (int) env( 'ECOMMERCE_LABEL_CLAIM_TTL_MINUTES', 10 ),
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
    | `vip.order_count`              — Paid orders after which a customer
    |                                   becomes a VIP and
    |                                   `ap.ecommerce.customer.becameVip`
    |                                   fires (default: 0, off).
    |
    | `vip.lifetime_spend`           — Lifetime spend, net of refunds, in
    |                                   minor units of the base currency,
    |                                   after which a customer becomes a VIP
    |                                   (default: 0, off).
    |
    */

    'customers' => [
        'claim_rate_limit'          => (int) env( 'ECOMMERCE_CLAIM_RATE_LIMIT', 5 ),
        'claim_rate_window_minutes' => (int) env( 'ECOMMERCE_CLAIM_RATE_WINDOW_MINUTES', 60 ),
        'vip'                       => [
            'order_count'    => (int) env( 'ECOMMERCE_VIP_ORDER_COUNT', 0 ),
            'lifetime_spend' => (int) env( 'ECOMMERCE_VIP_LIFETIME_SPEND', 0 ),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity log
    |--------------------------------------------------------------------------
    |
    | Append-only history for products, customers, and promotions (orders
    | keep their own timeline). See docs/activity-log.md.
    |
    | `enabled` — Record activity entries (default: true). When false, the
    |             model observers and services write nothing.
    |
    */

    'activity_log' => [
        'enabled' => (bool) env( 'ECOMMERCE_ACTIVITY_LOG_ENABLED', true ),
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
            'per_ip'   => (int) env( 'ECOMMERCE_RATE_CART_MUTATE_PER_IP', 300 ),
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

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    |
    | Minimum level for the dedicated `ecommerce` log channel. The channel
    | itself is registered automatically from the service provider and
    | emits structured JSON via `EcommerceLogFormatter`. Host applications
    | that want a different driver, path, or handler can override the
    | channel entirely by defining `logging.channels.ecommerce` in their
    | own `config/logging.php` — the auto-registration is skipped whenever
    | that key is already present.
    |
    */

    'log_level' => env( 'ECOMMERCE_LOG_LEVEL', 'debug' ),

    /*
    |--------------------------------------------------------------------------
    | Payment gateways
    |--------------------------------------------------------------------------
    |
    | Configuration for the built-in payment gateway adapters. Each gateway
    | is registered against `PaymentGatewayRegistry` from the engine service
    | provider's `boot()` only when its `enabled` flag is `true` — that
    | keeps a mis-configured (or intentionally disabled) provider from
    | routing traffic through it.
    |
    | `stripe`:
    |   - `enabled`         — Register the Stripe gateway on boot.
    |   - `secret_key`      — Stripe API secret key (server-side only; NEVER
    |                         expose to the client).
    |   - `publishable_key` — Publishable key surfaced to the storefront so
    |                         Stripe Elements can initialize.
    |   - `webhook_secret`  — Endpoint secret (`whsec_…`) used to verify
    |                         inbound webhook signatures. Several secrets,
    |                         comma-separated, are all accepted, so a secret
    |                         can be rotated without dropping deliveries.
    |   - `api_version`     — Optional pinned Stripe API version.
    |   - `capture_method`  — `automatic` (default), `manual`, or
    |                         `automatic_async`. `manual` uses the classic
    |                         auth/capture split: the money is only held
    |                         when the shopper confirms, so the fraud check
    |                         at finalize runs before anything is captured.
    |                         With `automatic` the shopper's confirmation
    |                         captures, and a blocked payment is refunded.
    |   - `appearance`      — Stripe Elements appearance options passed to
    |                         storefronts through the client config.
    |   - `webhook_route`   — Path (relative to app root) where the
    |                         `POST` webhook endpoint is registered.
    |
    */

    'gateways' => [
        'stripe' => [
            'enabled'         => (bool) env( 'ECOMMERCE_STRIPE_ENABLED', false ),
            'secret_key'      => env( 'ECOMMERCE_STRIPE_SECRET_KEY' ),
            'publishable_key' => env( 'ECOMMERCE_STRIPE_PUBLISHABLE_KEY' ),
            'webhook_secret'  => env( 'ECOMMERCE_STRIPE_WEBHOOK_SECRET' ),
            'api_version'     => env( 'ECOMMERCE_STRIPE_API_VERSION' ),
            'capture_method'  => env( 'ECOMMERCE_STRIPE_CAPTURE_METHOD', 'automatic' ),
            'webhook_route'   => env( 'ECOMMERCE_STRIPE_WEBHOOK_ROUTE', 'ecommerce/webhooks/stripe' ),
            'appearance'      => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fraud
    |--------------------------------------------------------------------------
    |
    | Active `FraudProvider` used by `PaymentOrchestrator::finalize()` to
    | assess the pending authorization between the authorize and capture
    | steps. The provider must be registered against `FraudProviderRegistry`.
    |
    | Reference implementations (`stripe-radar`, `always-approve`) ship in
    | the engine — until at least one provider is registered, the
    | orchestrator throws rather than defaulting to a permissive mode,
    | because silently skipping fraud assessment is exactly the failure
    | mode this contract exists to prevent.
    |
    | `fail_open` — When a provider can't reach its service (or, for
    | Stripe Radar, the payment has no charge to read yet), approve anyway
    | (`true`) or hold the payment for review (`false`, the default). Held
    | payments stay authorized on a pending order until an admin approves
    | or cancels them.
    |
    */

    'fraud' => [
        'provider'  => env( 'ECOMMERCE_FRAUD_PROVIDER', 'always-approve' ),
        'fail_open' => (bool) env( 'ECOMMERCE_FRAUD_FAIL_OPEN', false ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | `country` — ISO 3166-1 alpha-2 country the store trades from. Used
    | where an order or cart has no address of its own (an all-digital
    | order's fraud assessment, for example).
    |
    */

    'store' => [
        'country' => strtoupper( (string) env( 'ECOMMERCE_STORE_COUNTRY', 'US' ) ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax
    |--------------------------------------------------------------------------
    |
    | `provider`            — Registry key of the single active TaxProvider
    |                         (engine spec §4.5). Core ships `manual`, which
    |                         reads the `tax_rates` table; satellites add
    |                         `stripe-tax`, `taxjar`, `avalara`, …
    |
    | `prices_include_tax`  — `true` for tax-inclusive (EU-style) pricing:
    |                         tax is back-calculated out of line prices.
    |                         `false` (default) adds tax on top (US-style).
    |
    | `default_class`       — Tax class used for products with a null
    |                         `tax_class_key`.
    |
    | `shipping_tax_class`  — Tax class whose `is_shipping_taxable` rates
    |                         are levied on the shipping charge.
    |
    */

    'tax' => [
        'provider'           => env( 'ECOMMERCE_TAX_PROVIDER', 'manual' ),
        'prices_include_tax' => (bool) env( 'ECOMMERCE_PRICES_INCLUDE_TAX', false ),
        'default_class'      => env( 'ECOMMERCE_DEFAULT_TAX_CLASS', 'standard' ),
        'shipping_tax_class' => env( 'ECOMMERCE_SHIPPING_TAX_CLASS', 'standard' ),
    ],

    /*
    |--------------------------------------------------------------------------
    | REST API
    |--------------------------------------------------------------------------
    |
    | `version`          — Path segment appended to `api_prefix`
    |                      (`/api/ecommerce/v1`). Bumped only on breaking
    |                      changes (parent plan §12.1).
    |
    | `default_per_page` — Page size for cursor-paginated listings.
    |
    | `max_per_page`     — Upper bound a client may request via `per_page`.
    |
    | `middleware`       — Middleware applied to every REST route before the
    |                      route-specific auth / rate-limit / idempotency
    |                      stack.
    |
    | `auth_middleware`  — Authentication middleware for non-public routes.
    |                      `ecommerce.service-signature` authenticates
    |                      signed service-to-service calls (engine spec
    |                      §11.4) and is a no-op for everything else;
    |                      `auth:sanctum` then accepts Sanctum tokens and
    |                      cookie sessions. Admin routes then require the
    |                      Gate ability `ecommerce.{resource}.{action}` (or
    |                      the umbrella `ecommerce.admin`), filterable via
    |                      `ap.ecommerce.abilities.{resource}.{action}`.
    |                      With neither defined, admin routes deny. A
    |                      Sanctum token further narrows access to its
    |                      abilities: `ecommerce:admin`,
    |                      `ecommerce:storefront`, or per-resource scopes
    |                      such as `ecommerce:orders.read`.
    |
    | `services`         — Service-to-service callers, keyed by the
    |                      signature `keyId`. Each entry has a `secret`
    |                      (shared HMAC key) and the token `abilities` the
    |                      service is granted, e.g.
    |                        'erp-sync' => [
    |                            'secret'    => env( 'ECOMMERCE_ERP_SECRET' ),
    |                            'abilities' => [ 'ecommerce:orders.read' ],
    |                        ],
    |
    | `signature_tolerance_seconds` — Maximum clock skew accepted on a
    |                      signed request's `Date` header (default 300s).
    |
    */

    'api' => [
        'version'          => env( 'ECOMMERCE_API_VERSION', 'v1' ),
        'default_per_page' => (int) env( 'ECOMMERCE_API_DEFAULT_PER_PAGE', 25 ),
        'max_per_page'     => (int) env( 'ECOMMERCE_API_MAX_PER_PAGE', 100 ),
        'middleware'       => [ 'api', 'ecommerce.request-id' ],
        'auth_middleware'  => [ 'ecommerce.service-signature', 'auth:sanctum' ],

        'services' => [],

        'signature_tolerance_seconds' => (int) env( 'ECOMMERCE_SERVICE_SIGNATURE_TOLERANCE', 300 ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound webhooks
    |--------------------------------------------------------------------------
    |
    | Signed deliveries to `webhook_subscriptions` endpoints (engine spec
    | §8). Each delivery is POSTed with
    | `X-ArtisanPack-Signature: t=<ts>, v1=<hmac_sha256(ts.payload, secret)>`.
    |
    | `events`                 — Domain event classes delivered as webhooks.
    |                            The wire name comes from the class
    |                            (`OrderRefunded` → `order.refunded`).
    | `backoff_seconds`        — Delay after the 1st, 2nd, … failed attempt
    |                            (default 1m … 24h; the last entry repeats).
    | `max_attempts`           — Attempts per delivery before it is given
    |                            up on (default 10).
    | `disable_after_failures` — Consecutive failures (across deliveries)
    |                            before a subscription is switched off and
    |                            `WebhookSubscriptionDisabled` fires.
    | `timeout`                — HTTP timeout per attempt, in seconds.
    | `claim_seconds`          — How long a queued attempt holds its row
    |                            before the retry sweep may queue it again.
    | `allow_insecure_urls`    — Accept `http://` endpoints (local dev only).
    | `allow_private_hosts`    — Accept endpoints resolving to loopback /
    |                            private / link-local addresses. Off by
    |                            default to block SSRF into the store's own
    |                            network; enable for local development or
    |                            receivers on a private network.
    | `include_admin_fields`   — Render admin-only resource fields (payment
    |                            references, IP / user agent, product meta,
    |                            cost prices) in payloads. Off by default.
    | `connection` / `queue`   — Where `DeliverWebhookJob` is queued.
    |
    */

    'webhooks' => [
        'events' => [
            ArtisanPackUI\Ecommerce\Events\OrderStatusChanged::class,
            ArtisanPackUI\Ecommerce\Events\OrderSubstatusChanged::class,
            ArtisanPackUI\Ecommerce\Events\OrderCancelled::class,
            ArtisanPackUI\Ecommerce\Events\OrderEdited::class,
            ArtisanPackUI\Ecommerce\Events\OrderRefunded::class,
            ArtisanPackUI\Ecommerce\Events\PaymentSucceeded::class,
            ArtisanPackUI\Ecommerce\Events\PaymentFailed::class,
            ArtisanPackUI\Ecommerce\Events\PaymentRefunded::class,
            ArtisanPackUI\Ecommerce\Events\FraudBlocked::class,
        ],
        'backoff_seconds'        => [ 60, 300, 900, 1_800, 3_600, 7_200, 14_400, 28_800, 43_200, 86_400 ],
        'max_attempts'           => (int) env( 'ECOMMERCE_WEBHOOK_MAX_ATTEMPTS', 10 ),
        'disable_after_failures' => (int) env( 'ECOMMERCE_WEBHOOK_DISABLE_AFTER', 10 ),
        'timeout'                => (int) env( 'ECOMMERCE_WEBHOOK_TIMEOUT', 10 ),
        'claim_seconds'          => (int) env( 'ECOMMERCE_WEBHOOK_CLAIM_SECONDS', 300 ),
        'allow_insecure_urls'    => (bool) env( 'ECOMMERCE_WEBHOOK_ALLOW_INSECURE_URLS', false ),
        'allow_private_hosts'    => (bool) env( 'ECOMMERCE_WEBHOOK_ALLOW_PRIVATE_HOSTS', false ),
        'include_admin_fields'   => (bool) env( 'ECOMMERCE_WEBHOOK_INCLUDE_ADMIN_FIELDS', false ),
        'connection'             => env( 'ECOMMERCE_WEBHOOK_QUEUE_CONNECTION' ),
        'queue'                  => env( 'ECOMMERCE_WEBHOOK_QUEUE' ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | Product search runs through Laravel Scout (parent plan §4.1).
    |
    | `driver` — Scout engine for products. Defaults to `database` so search
    |            works with no extra infrastructure. Set it to `meilisearch`,
    |            `typesense`, or `algolia` (after installing that engine's
    |            client) — or to an empty value to follow `scout.driver`.
    | `index`  — Index name for dedicated engines (`scout.prefix` is
    |            prepended).
    |
    */

    'search' => [
        'driver' => env( 'ECOMMERCE_SEARCH_DRIVER', 'database' ),
        'index'  => env( 'ECOMMERCE_SEARCH_INDEX', 'ecommerce_products' ),
    ],

    /*
    |--------------------------------------------------------------------------
    | GraphQL
    |--------------------------------------------------------------------------
    |
    | The `ecommerce` schema on rebing/graphql-laravel, served at
    | `/graphql/ecommerce` (engine spec §10). Toggle it with
    | `features.graphql`.
    |
    | `middleware`    — Route middleware. The endpoint serves public and
    |                   authenticated fields in one request, so it resolves
    |                   credentials without requiring them
    |                   (`ecommerce.optional-auth`); each field then enforces
    |                   its REST counterpart's ability and rate limit. An
    |                   `Idempotency-Key` header is honoured when sent.
    | `max_depth`     — Maximum query nesting depth (0 disables the check).
    | `max_complexity`— Maximum operation cost (0 disables the check). Each
    |                   field costs 1; a connection multiplies its selection
    |                   by `first` and a relation list by
    |                   `list_complexity_factor`, so nested lists and
    |                   aliases add up quickly.
    | `list_complexity_factor` — Assumed size of a relation list (default 5).
    | `max_batch`     — Maximum operations in one batched request.
    | `introspection` — Allow `__schema` / `__type` queries on this schema.
    |                   Null (the default) allows them everywhere except in
    |                   production. These four limits govern the ecommerce
    |                   schema only: rebing's global `graphql.security` values
    |                   don't apply to it, and the engine never changes them
    |                   for a host app's own schemas.
    | `subscriptions` — Broadcast subscription events over Laravel
    |                   broadcasting (requires a configured broadcaster).
    |
    */

    'graphql' => [
        'middleware'     => [ 'api', 'ecommerce.request-id', 'ecommerce.graphql-batch', 'ecommerce.service-signature', 'ecommerce.optional-auth', 'ecommerce.idempotency:optional' ],
        'max_depth'      => (int) env( 'ECOMMERCE_GRAPHQL_MAX_DEPTH', 10 ),
        'max_complexity' => (int) env( 'ECOMMERCE_GRAPHQL_MAX_COMPLEXITY', 5_000 ),

        'list_complexity_factor' => (int) env( 'ECOMMERCE_GRAPHQL_LIST_COMPLEXITY_FACTOR', 5 ),
        'max_batch'              => (int) env( 'ECOMMERCE_GRAPHQL_MAX_BATCH', 10 ),
        'introspection'          => null === env( 'ECOMMERCE_GRAPHQL_INTROSPECTION' ) ? null : (bool) env( 'ECOMMERCE_GRAPHQL_INTROSPECTION' ),
        'subscriptions'          => (bool) env( 'ECOMMERCE_GRAPHQL_SUBSCRIPTIONS', false ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reviews
    |--------------------------------------------------------------------------
    |
    | Product reviews (parent plan §5.12). New reviews wait in the
    | moderation queue unless the bound `ReviewModerator` decides otherwise
    | (the default, `NoopReviewModerator`, leaves them all for a human).
    | Submissions are rate-limited by `rate_limits.review.submit`.
    |
    | `allow_guests`   — Accept reviews from shoppers who aren't signed in
    |                    (they must give a name and email).
    | `honeypot_field` — Name of a hidden form field real shoppers leave
    |                    empty. Submissions that fill it in are answered as
    |                    usual but filed straight to spam.
    |
    */

    'reviews' => [
        'allow_guests'   => (bool) env( 'ECOMMERCE_REVIEWS_ALLOW_GUESTS', true ),
        'honeypot_field' => env( 'ECOMMERCE_REVIEWS_HONEYPOT_FIELD', 'website' ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Digital delivery
    |--------------------------------------------------------------------------
    |
    | Download entitlements for digital files (parent plan §5.13). Links
    | carry opaque server-issued tokens (only their sha256 is stored).
    |
    | `auto_issue`           — Issue downloads (and license keys) for a paid
    |                          order when `ap.ecommerce.payment.succeeded`
    |                          fires, then email the links.
    | `download_limit`       — Downloads per entitlement (0 = unlimited).
    | `download_expiry_days` — Days an entitlement stays valid (0 = never
    |                          expires).
    | `disk`                 — Filesystem disk for files with a `path` but
    |                          no `disk`.
    | `stream_window_minutes`— How long after a stream starts (and spends a
    |                          download) its later byte-range requests are
    |                          served without spending another one.
    | `stream_byte_allowance`— Those range requests may send at most this
    |                          many times the file size in total.
    | `allowed_disks`        — Filesystem disks digital files may live on.
    |                          Keeps an admin from attaching (and so
    |                          downloading) files from any other disk.
    | `revoke_on_refund`     — Expire downloads and revoke license keys when
    |                          an order is fully refunded or cancelled.
    |
    */

    'digital' => [
        'auto_issue'            => (bool) env( 'ECOMMERCE_DIGITAL_AUTO_ISSUE', true ),
        'download_limit'        => (int) env( 'ECOMMERCE_DIGITAL_DOWNLOAD_LIMIT', 5 ),
        'download_expiry_days'  => (int) env( 'ECOMMERCE_DIGITAL_DOWNLOAD_EXPIRY_DAYS', 30 ),
        'disk'                  => env( 'ECOMMERCE_DIGITAL_DISK', 'local' ),
        'stream_window_minutes' => (int) env( 'ECOMMERCE_DIGITAL_STREAM_WINDOW_MINUTES', 240 ),
        'stream_byte_allowance' => (int) env( 'ECOMMERCE_DIGITAL_STREAM_BYTE_ALLOWANCE', 3 ),
        'allowed_disks'         => [ env( 'ECOMMERCE_DIGITAL_DISK', 'local' ) ],
        'revoke_on_refund'      => (bool) env( 'ECOMMERCE_DIGITAL_REVOKE_ON_REFUND', true ),
    ],

    /*
    |--------------------------------------------------------------------------
    | License keys
    |--------------------------------------------------------------------------
    |
    | Defaults for keys issued to products with `meta.licensing.enabled`
    | (per-product `activations_limit` / `expires_in_days` win). A line's
    | quantity multiplies its activation limit.
    |
    | `activations_limit` — Machines per key (0 = unlimited).
    | `expires_in_days`   — Days a key stays valid (null / 0 = never).
    |
    */

    'licenses' => [
        'activations_limit' => (int) env( 'ECOMMERCE_LICENSE_ACTIVATIONS_LIMIT', 5 ),
        'expires_in_days'   => env( 'ECOMMERCE_LICENSE_EXPIRES_IN_DAYS' ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | The notification catalog (parent plan §14). Copy is Twig, edited per
    | template and locale through the admin API and rendered in a sandbox.
    |
    | `enabled`                   — Send catalog notifications from the
    |                               engine's lifecycle hooks.
    | `admin_emails`              — Recipients of staff notifications (order
    |                               paid, stock alerts, reviews to moderate).
    | `store_name`                — `Store.name` in templates (defaults to
    |                               `app.name`).
    | `support_email`             — `Store.support_email` (defaults to
    |                               `mail.from.address`).
    | `default_locale`            — Locale template rows are seeded in and
    |                               fall back to (defaults to
    |                               `app.fallback_locale`).
    | `preference_channels`       — Channels customers set preferences for.
    | `review_request_delay_days` — Days after delivery to ask for a review
    |                               (0 = immediately; needs a queue worker
    |                               for any delay).
    |
    */

    'notifications' => [
        'enabled'                   => (bool) env( 'ECOMMERCE_NOTIFICATIONS_ENABLED', true ),
        'admin_emails'              => array_values( array_filter( explode( ',', (string) env( 'ECOMMERCE_NOTIFICATIONS_ADMIN_EMAILS', '' ) ) ) ),
        'store_name'                => env( 'ECOMMERCE_STORE_NAME' ),
        'support_email'             => env( 'ECOMMERCE_SUPPORT_EMAIL' ),
        'default_locale'            => env( 'ECOMMERCE_NOTIFICATIONS_DEFAULT_LOCALE' ),
        'preference_channels'       => [ 'mail' ],
        'review_request_delay_days' => (int) env( 'ECOMMERCE_REVIEW_REQUEST_DELAY_DAYS', 7 ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kanban
    |--------------------------------------------------------------------------
    |
    | Order kanban boards (parent plan §9).
    |
    | `auto_route`           — Put orders on their matching boards when
    |                          `ap.ecommerce.order.placed` fires, and re-run
    |                          routing when `ap.ecommerce.order.edited` fires.
    | `broadcast`            — Broadcast card moves on
    |                          `private-ecommerce.kanban.board.{board}`
    |                          (requires a configured broadcaster). Channel
    |                          members need the `kanbanBoard.view` ability.
    | `default_card_widgets` — Widgets on cards in columns (and boards) that
    |                          don't choose their own.
    | `stale_after_days`     — The `days-in-column` widget turns `warning`
    |                          after this many days and `danger` after twice
    |                          as many.
    | `dispatchable_jobs`    — Job classes the `dispatch-job` automation may
    |                          queue. Automations are admin data, so only
    |                          listed classes can be instantiated.
    |
    */

    'kanban' => [
        'auto_route'           => (bool) env( 'ECOMMERCE_KANBAN_AUTO_ROUTE', true ),
        'broadcast'            => (bool) env( 'ECOMMERCE_KANBAN_BROADCAST', false ),
        'default_card_widgets' => [ 'total', 'item-count', 'customer' ],
        'stale_after_days'     => (int) env( 'ECOMMERCE_KANBAN_STALE_AFTER_DAYS', 3 ),
        'dispatchable_jobs'    => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    |
    | Locale-driven formatting (parent plan §16.5). The engine ships `en`,
    | `es`, `fr`, and `de` catalogues; money is formatted with PHP's
    | `NumberFormatter` (the `intl` extension is required).
    |
    | `tax_labels` — Per-locale override for the tax line label on receipts
    |                and checkout summaries. The defaults come from the
    |                shipped catalogues ("Tax" / "IVA" / "TVA" / "USt.").
    |                Example: [ 'en' => 'Sales Tax' ].
    |
    | `regional_fallback` — Let regional locales (`de_DE`, `es-MX`) fall back
    |                to their base language's JSON catalogue. Laravel does
    |                not do this for JSON keys on its own; the engine wraps
    |                the translation loader to add it. Applies app-wide.
    |
    */

    'localization' => [
        'tax_labels'        => [],
        'regional_fallback' => (bool) env( 'ECOMMERCE_REGIONAL_LOCALE_FALLBACK', true ),
    ],

    /*
    |--------------------------------------------------------------------------
    | cms-framework
    |--------------------------------------------------------------------------
    |
    | When `artisanpack-ui/cms-framework` is installed, the engine registers
    | every ecommerce ability as an RBAC permission plus a `shop-manager` role
    | holding them all (`php artisan ecommerce:sync-permissions`, also run
    | after `migrate`), and defines the abilities as Gates so RBAC can grant
    | them. Set `enabled` to false to manage permissions yourself.
    |
    */

    'cms_framework' => [
        'enabled' => (bool) env( 'ECOMMERCE_CMS_FRAMEWORK_ENABLED', true ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sentry
    |--------------------------------------------------------------------------
    |
    | When `sentry/sentry-laravel` is installed, the engine attaches every
    | `EcommerceException::context()` payload to the Sentry scope under the
    | `ecommerce` key (parent plan §16.3). Set `enabled` to false to opt out
    | without uninstalling Sentry.
    |
    */

    'sentry' => [
        'enabled' => (bool) env( 'ECOMMERCE_SENTRY_ENABLED', true ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Satellites
    |--------------------------------------------------------------------------
    |
    | Satellite lifecycle + contract verification (parent plan §15.2, §16.6).
    |
    | `verification.report_path` — Where `ecommerce:verify-satellite` writes
    |                              its JSON report (relative to the base path).
    | `verification.signing_key` — Base64 Ed25519 secret key used to sign the
    |                              report (`verify-report.sig`). CI only;
    |                              leave unset locally.
    | `verification.public_key`  — Base64 Ed25519 public key used to check a
    |                              signed report.
    |
    */

    'satellites' => [
        'verification' => [
            'report_path' => env( 'ECOMMERCE_VERIFY_REPORT_PATH', '.ecommerce-verify-report.json' ),
            'signing_key' => env( 'ECOMMERCE_VERIFY_SIGNING_KEY' ),
            'public_key'  => env( 'ECOMMERCE_VERIFY_PUBLIC_KEY' ),
        ],
    ],

];
