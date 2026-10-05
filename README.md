# ArtisanPack UI Ecommerce

The headless commerce engine for the ArtisanPack UI ecosystem. It covers the
catalog and search, carts and checkout, pricing and promotions, tax, shipping,
payments and fraud screening, orders, refunds, customers, reviews, digital
delivery and license keys, notifications, an order kanban, outbound webhooks,
and REST + GraphQL APIs. It has no UI of its own. Storefront and admin
packages, plus integrations such as carriers, gateways, and tax services, plug
in as **satellites** through the engine's [contracts](docs/contracts.md),
registries, and [hooks](docs/hooks.md).

## Requirements

- PHP **8.2+** with the **`intl`** and **`bcmath`** extensions (locale-aware money formatting and exact money arithmetic)
- Laravel **12 or 13**
- A database supported by Laravel (MySQL 8, PostgreSQL, SQLite)
- A queue worker, for webhook delivery, payment reconciliation, and queued notifications
- The scheduler (`schedule:run` every minute), for reservation expiry, webhook retries, cart and ledger pruning, and FX-rate refreshes
- A cache store that supports locks (Redis, database, Memcached) when more than one server runs the scheduler

## Installation

```bash
composer require artisanpack-ui/ecommerce
```

The service provider is auto-discovered. The engine loads its own migrations,
and every table it creates starts with `ecommerce_`, so a migrate is enough:

```bash
php artisan migrate
```

Optionally publish the config, migrations, or translation catalogues to edit them:

```bash
php artisan vendor:publish --tag=ecommerce-config      # config/artisanpack/ecommerce.php
php artisan vendor:publish --tag=ecommerce-migrations  # database/migrations
php artisan vendor:publish --tag=ecommerce-lang        # lang/vendor/ecommerce (overrides the shipped catalogues)
```

If you publish the migrations and run your own copies, stop the engine from
loading its own by calling `Ecommerce::ignoreMigrations()` from a service
provider's `register()` method.

The admin and shopper APIs authenticate with Laravel Sanctum. If your app
doesn't use it yet, run `php artisan install:api` so the
`personal_access_tokens` table exists. Then grant admins access (abilities are
default-deny):

```php
// app/Providers/AppServiceProvider.php → boot()
Gate::define( 'ecommerce.admin', fn ( User $user ): bool => $user->is_admin );
```

With `artisanpack-ui/cms-framework`
installed, the engine registers its abilities as RBAC permissions plus a
`shop-manager` role instead. See [docs/permissions.md](docs/permissions.md).

### Optional packages

The engine runs without these. Install the ones you need:

| Package | Adds |
|---|---|
| `stripe/stripe-php` | The Stripe payment gateway and Stripe Radar fraud screening |
| `rebing/graphql-laravel` | The GraphQL API at `/graphql/ecommerce` |
| `sentry/sentry-laravel` | Engine exception context in Sentry |
| `artisanpack-ui/cms-framework` | RBAC permissions and the `shop-manager` role |
| `artisanpack-ui/media-library` | Product images and review photos stored as media |

## Configuration

Everything lives in `config/artisanpack/ecommerce.php`. It's merged
automatically, so publishing is optional, and most keys have an
`ECOMMERCE_*` env var. If you published the config before upgrading, compare
it with the shipped file for new keys.

| Key | What it controls |
|---|---|
| `base_currency` | Store base currency for FX snapshots and reporting (`ECOMMERCE_BASE_CURRENCY`, default `USD`) |
| `timezone` | Store time zone for reports, dates in notifications, and date-range promotions (defaults to `app.timezone`) |
| `currency` | Enabled currencies, the active FX-rate provider (`config` / `frankfurter`), and the static rate table |
| `route_prefix`, `api_prefix` | Storefront route prefix (`shop`) and REST prefix (`api/ecommerce`) |
| `cart` | Cart lifetime (`ttl_days`), line cap (`max_lines`), guest-cart cookie, merge on login, abandoned-cart timing |
| `promotions` | Whether coupon errors say why a coupon failed (`verbose_coupon_errors`) |
| `checkout` | Reservation TTL, guest checkout and account creation, payment reconciliation delay, guest order links (`order_view_url`, `order_view_ttl_days`), and guest lookup lockouts |
| `fulfillment` | Shipping/tax allocation strategy for partial shipments and refunds; whether unpaid orders can ship |
| `customers` | Guest-order claim rate limits |
| `activity_log` | Whether admin changes to products, customers, and promotions are recorded |
| `idempotency` | `Idempotency-Key` TTLs, in-flight wait, and the problem-URL base |
| `rate_limits` | Per-policy request limits |
| `features` | Toggles for `rest`, `graphql`, `scout` |
| `log_level` | Level of the structured-JSON `ecommerce` log channel |
| `gateways` | Stripe keys, webhook secrets, capture method, and appearance (`ECOMMERCE_STRIPE_ENABLED`) |
| `fraud` | Active fraud provider(s); a comma list runs them as a chain; fail-open behaviour |
| `store` | The store's country (the default destination for display prices and fraud checks) |
| `tax` | Active tax provider, tax-inclusive pricing, and default tax classes |
| `api` | Version, page sizes, middleware, auth stack, signed service callers and their replay cache, catalog cache lifetime |
| `webhooks` | Outbound webhook events, retry backoff, SSRF guards, and the inbound body-size limit |
| `search` | Active search provider, Scout driver, and index |
| `graphql` | Middleware, depth, complexity, batch limits, introspection, and subscriptions |
| `reviews` | Guest reviews, required purchase, one review per customer, photos, and the honeypot field |
| `digital`, `licenses` | Download links and license-key policy |
| `notifications` | Store name, support email, admin recipients, default locale, preferences page, review-request delay |
| `kanban` | Auto-routing, broadcasting, and default card widgets |
| `localization` | Supported API locales, per-locale tax label overrides, regional-locale catalogue fallback |
| `cms_framework` | Whether abilities are registered as cms-framework permissions |
| `sentry` | Optional Sentry context integration (`ECOMMERCE_SENTRY_ENABLED`) |
| `satellites` | Contract-verification report path and signing keys |
| `inventory` | Whether storefront stock status shows quantities (`show_quantity`) |
| `schedule` | Turn the engine schedule off (`enabled`) or change a task's cadence (`tasks`) |
| `retention` | Days to keep inbound webhooks, webhook deliveries, activity entries, and download events |

## Quick-start: from `composer require` to a first order

This walkthrough uses only what the engine ships. The engine provides the
checkout services and APIs. Your storefront builds the checkout pages on top of
them. See [docs/checkout.md](docs/checkout.md) for the full flow, including payment.

> **Just want data to click around?** Run `php artisan ecommerce:seed-demo`
> for demo products, customers, orders spread over the last 90 days, and a
> kanban board with sample automations. Add `--fresh` to reseed.

**1. Install and migrate** (see above).

**2. Create a product with a price and stock, and somewhere to ship it.** Do this in `php artisan tinker` or a seeder:

```php
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;

$product = Product::query()->create( [
    'type'         => 'simple',
    'name'         => 'Coffee Mug',
    'slug'         => 'coffee-mug',
    'sku'          => 'MUG-001',
    'status'       => 'active',
    'published_at' => now(),
] );

$product->prices()->create( [ 'currency' => 'USD', 'price_amount' => 1500 ] ); // minor units: $15.00

InventoryItem::query()->create( [
    'stockable_type'   => $product->getMorphClass(),
    'stockable_id'     => $product->id,
    'quantity_on_hand' => 100,
] );

$zone = ShippingZone::query()->create( [ 'name' => 'United States', 'country_codes' => [ 'US' ], 'is_active' => true ] );

ShippingMethod::query()->create( [
    'zone_id'   => $zone->id,
    'key'       => 'flat-rate',
    'label'     => 'Standard shipping',
    'config'    => [ 'amount' => 500 ], // $5.00
    'is_active' => true,
] );
```

**3. Browse and build a cart over the REST API.** No credentials are needed,
because the cart token is the credential. Cart writes require an
`Idempotency-Key`.

```bash
BASE=https://your-app.test/api/ecommerce/v1

curl -s "$BASE/products"

TOKEN=$(curl -s -X POST "$BASE/carts" \
  -H "Content-Type: application/json" -H "Idempotency-Key: $(uuidgen)" \
  -d '{"currency":"USD","email":"ada@example.com"}' | jq -r '.data.token')

curl -s -X POST "$BASE/carts/$TOKEN/items" \
  -H "Content-Type: application/json" -H "Idempotency-Key: $(uuidgen)" \
  -d '{"product_id":1,"quantity":2}' | jq '.data.total'
# → { "amount": 3000, "currency": "USD" }
```

**4. Check out and place the order.** A storefront walks the same steps over
`checkout/{cart}`. In PHP (`$token` is the cart token from step 3):

```php
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\OrderPlacementService;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;

$cart     = Cart::query()->where( 'token', $token )->firstOrFail();
$checkout = app( CheckoutService::class );

$address = new Address(
    address1: '1 Main St', city: 'Chicago', countryCode: 'US',
    firstName: 'Ada', lastName: 'Lovelace', regionCode: 'IL', postalCode: '60601',
);

$checkout->setEmail( $cart, 'ada@example.com' );
$checkout->setAddress( $cart, $address );
$checkout->setShippingMethod( $cart, $checkout->shippingRates( $cart )->first()->id() );

// With a payment gateway (Stripe): setPaymentGateway(), createPaymentSession()
// for the storefront to confirm, then finalize(). See docs/checkout.md.
// Without one, place the order directly; its payment stays pending.
$order = app( OrderPlacementService::class )->place( $cart->refresh() );

$order->order_number; // e.g. K7QM2XW9
```

Placing the order fires `ap.ecommerce.order.placed` and `OrderPlaced`, which send
the confirmation email, put the order on its kanban board, and queue the
`order.placed` webhook.

**5. Move the order along.** Payment confirmation does this for you. Without a
gateway, use the status machine:

```php
app( \ArtisanPackUI\Ecommerce\Services\OrderStatusMachine::class )->transition( $order, 'processing' );
```

**6. See it in the admin API.** Create a Sanctum token for an admin user and call:

```bash
curl -s "$BASE/orders?include=items" -H "Authorization: Bearer $ADMIN_TOKEN"
```

From PHP, the `ecommerce()` helper (or the `Ecommerce` facade) hands out the
main services: `cart()`, `checkout()`, `orders()`, `payments()`, `catalog()`,
`customers()`, and `inventory()`.

Next steps: [checkout](docs/checkout.md) · [HTTP API](docs/api.md) · [hooks](docs/hooks.md) · [events](docs/events.md) · [contracts](docs/contracts.md).

## Artisan commands

| Command | Purpose | Scheduled |
|---|---|---|
| `ecommerce:release-expired-reservations` | Release inventory reservations past their TTL | every minute |
| `ecommerce:retry-webhook-deliveries {--limit=500}` | Re-queue outbound webhook deliveries that are due | every minute |
| `ecommerce:flag-abandoned-carts` | Flag carts left in checkout as abandoned and fire `CartAbandoned` | every 5 minutes |
| `ecommerce:reconcile-payments {--minutes=}` | Settle checkouts whose payment the provider confirmed or cancelled | every 15 minutes |
| `ecommerce:prune-idempotency-records` | Delete expired idempotency records | hourly |
| `ecommerce:audit-order-status` | Report orders whose status and sub-status have drifted apart | daily 02:15 |
| `ecommerce:prune-carts` | Delete expired carts and old converted carts, releasing their stock holds | daily 03:30 |
| `ecommerce:prune-ledgers` | Delete webhook, activity, and download-event rows past their retention | daily 03:45 |
| `ecommerce:refresh-fx-rates {--currency=*}` | Fetch and cache exchange rates for every enabled currency | daily 05:30 |
| `ecommerce:generate-openapi {--output=} {--stdout}` | Write the OpenAPI 3.1 spec | |
| `ecommerce:lint:pci-columns {--path=*}` | CI lint: fail on PCI-sensitive column names in migrations | |
| `ecommerce:lint:translations {--path=*} {--lang=} {--no-engine} {--sync}` | CI lint: fail on user-facing strings not wrapped in `__()` or keys missing from a catalogue; `--no-engine` lints only a satellite's `--path`, `--sync` adds missing keys to `en.json` | |
| `ecommerce:sync-permissions` | Register the abilities as cms-framework permissions and the `shop-manager` role (also runs after `migrate`) | |
| `ecommerce:seed-demo {--fresh} {--products=50} {--orders=200} {--seed=} {--force} {--i-understand-this-deletes-production-data}` | Seed a demo catalog, customers, orders, and a kanban board | |
| `ecommerce:satellite:uninstall {package} {--purge} {--force}` | Deregister a satellite, keeping its data unless `--purge` is given | |
| `ecommerce:satellite:reinstall {package}` | Re-attach a previously uninstalled satellite | |
| `ecommerce:satellite:audit {--json} {--fail-on-orphans}` | List orphaned satellite tables and columns | |
| `ecommerce:verify-satellite {--sign} {--record} {--check-signature}` | Run the contract suites against a satellite and write a (signed) verification report | |

Scheduled commands register themselves on one server and never overlap; you
only need the Laravel scheduler running. Set `ECOMMERCE_SCHEDULE_ENABLED=false`
to run them yourself, or change a cadence under `schedule.tasks`. See
[docs/operations.md](docs/operations.md).

## Error tracking with Sentry (optional)

When [`sentry/sentry-laravel`](https://github.com/getsentry/sentry-laravel) is
installed, the engine detects it at runtime and registers
`EcommerceSentryIntegration`. Every engine exception (`EcommerceException`,
even when wrapped in another exception) then reaches Sentry with its
structured `context()` attached under the **`ecommerce`** context key. Models
in the context are reduced to `Class#id`, so customer data isn't sent.
There's nothing to configure. To opt out without uninstalling Sentry:

```dotenv
ECOMMERCE_SENTRY_ENABLED=false
```

## Localization

Every user-facing string goes through `__()`. The engine ships `en`, `es`,
`fr`, and `de` catalogues, formats money per locale with `NumberFormatter`,
answers the APIs in the language a client asks for with `Accept-Language`, and
sends notifications in each customer's language. See
[docs/localization.md](docs/localization.md).

## Documentation

| Topic | Doc |
|---|---|
| Checkout: carts, checkout services, payment sessions, placement | [docs/checkout.md](docs/checkout.md) |
| HTTP API: REST routes, errors, idempotency, rate limits, pagination, caching | [docs/api.md](docs/api.md) |
| API authentication, abilities, and token scopes | [docs/api-auth.md](docs/api-auth.md) |
| Permissions and cms-framework | [docs/permissions.md](docs/permissions.md) |
| GraphQL | [docs/graphql.md](docs/graphql.md) |
| OpenAPI generation | [docs/openapi.md](docs/openapi.md) |
| Products, prices, stock status, related products | [docs/products.md](docs/products.md) |
| Search | [docs/search.md](docs/search.md) |
| Customers and self-service | [docs/customers.md](docs/customers.md) |
| Reviews | [docs/reviews.md](docs/reviews.md) |
| Digital delivery and licenses | [docs/digital-delivery.md](docs/digital-delivery.md) |
| Notifications | [docs/notifications.md](docs/notifications.md) |
| Kanban | [docs/kanban.md](docs/kanban.md) |
| Reports | [docs/reports.md](docs/reports.md) |
| Settings | [docs/settings.md](docs/settings.md) |
| Activity log | [docs/activity-log.md](docs/activity-log.md) |
| Outbound and inbound webhooks | [docs/webhooks.md](docs/webhooks.md) |
| Hooks (actions and filters) | [docs/hooks.md](docs/hooks.md) (design spec: [docs/hooks-spec.md](docs/hooks-spec.md)) |
| Laravel events | [docs/events.md](docs/events.md) |
| Contracts and contract-test suites | [docs/contracts.md](docs/contracts.md) |
| Satellite directory | [docs/satellites.md](docs/satellites.md) |
| Satellite contract verification | [docs/satellite-verification.md](docs/satellite-verification.md) |
| Satellite lifecycle (install and uninstall) | [docs/satellite-lifecycle.md](docs/satellite-lifecycle.md) |
| Localization | [docs/localization.md](docs/localization.md) |
| Operations: schedule, retention, rate limits | [docs/operations.md](docs/operations.md) |
| PCI column lint | [docs/pci-column-lint.md](docs/pci-column-lint.md) |
| Upgrade guide | [docs/upgrade-guide.md](docs/upgrade-guide.md) |
| Changelog | [CHANGELOG.md](CHANGELOG.md) |

## Contributing

As an open source project, this package is open to contributions from anyone.
Please [read through the contributing guidelines](CONTRIBUTING.md) to learn
more about how you can contribute to this project.

## License

The MIT License. See [LICENSE](LICENSE) for details.
