# ArtisanPack UI Ecommerce

The headless commerce engine for the ArtisanPack UI ecosystem. It covers the
catalog, carts, pricing and promotions, tax, shipping, payments and fraud
screening, orders, refunds, reviews, digital delivery and license keys,
notifications, an order kanban, outbound webhooks, and REST + GraphQL APIs.
UI packages (storefront, admin, kanban) and integrations (carriers, gateways,
tax services) plug in as **satellites** through the engine's
[contracts](docs/contracts.md), registries, and [hooks](docs/hooks.md).

## Requirements

- PHP **8.2+** with the **`intl`** extension (used for locale-aware currency formatting)
- Laravel **12 or 13**
- A database supported by Laravel (MySQL 8, PostgreSQL, SQLite)
- A queue worker, for webhook delivery and queued notifications
- The scheduler (`schedule:run`), for reservation expiry, webhook retries, and idempotency pruning

## Installation

```bash
composer require artisanpack-ui/ecommerce
```

The service provider is auto-discovered. The engine loads its own migrations,
so a migrate is enough:

```bash
php artisan migrate
```

Optionally publish the config, migrations, or translation catalogues to edit them:

```bash
php artisan vendor:publish --tag=ecommerce-config      # config/artisanpack/ecommerce.php
php artisan vendor:publish --tag=ecommerce-migrations  # database/migrations
php artisan vendor:publish --tag=ecommerce-lang        # lang/vendor/ecommerce (overrides the shipped catalogues)
```

The admin API authenticates with Laravel Sanctum. If your app doesn't use it
yet, run `php artisan install:api` so the `personal_access_tokens` table
exists. Then grant admins access (abilities are default-deny):

```php
// app/Providers/AppServiceProvider.php → boot()
Gate::define( 'ecommerce.admin', fn ( User $user ): bool => $user->is_admin );
```

## Configuration

Everything lives in `config/artisanpack/ecommerce.php`. It's merged
automatically, so publishing is optional, and most keys have an
`ECOMMERCE_*` env var.

| Key | What it controls |
|---|---|
| `base_currency` | Store base currency for FX snapshots and reporting (`ECOMMERCE_BASE_CURRENCY`, default `USD`) |
| `currency` | Active FX-rate provider (`config` / `frankfurter`) and the static rate table |
| `route_prefix`, `api_prefix` | Storefront route prefix (`shop`) and REST prefix (`api/ecommerce`) |
| `checkout` | Inventory reservation TTL |
| `fulfillment` | Shipping/tax allocation strategy for partial shipments and refunds |
| `customers` | Guest-order claim rate limits |
| `idempotency` | `Idempotency-Key` TTLs, in-flight wait, and the problem-URL base |
| `rate_limits` | Per-policy request limits |
| `features` | Toggles for `rest`, `graphql`, `scout` |
| `log_level` | Level of the structured-JSON `ecommerce` log channel |
| `gateways` | Stripe keys, webhook secret, and capture method (`ECOMMERCE_STRIPE_ENABLED`) |
| `fraud` | Active fraud provider(s); a comma list runs them as a chain |
| `tax` | Active tax provider and tax behaviour |
| `api` | Version, page sizes, middleware, auth stack, and signed service callers |
| `webhooks` | Outbound webhook events, retry backoff, and SSRF guards |
| `search` | Scout driver and index for products |
| `graphql` | Middleware, depth, complexity, batch limits, and subscriptions |
| `reviews`, `digital`, `licenses` | Review moderation, download links, and license-key policy |
| `notifications` | Store name, support email, admin recipients, and default locale |
| `kanban` | Auto-routing, broadcasting, and default card widgets |
| `localization` | Per-locale tax label overrides; regional-locale catalogue fallback |
| `sentry` | Optional Sentry context integration (`ECOMMERCE_SENTRY_ENABLED`) |
| `satellites` | Contract-verification report path and signing keys |

## Quick-start: from `composer require` to a first order

This walkthrough uses only what the engine ships. The engine doesn't include
a checkout UI or a checkout-finalize endpoint; storefront satellites own
those. So step 4 creates the order in PHP, the same way a checkout does.

> **Just want data to click around?** Run `php artisan ecommerce:seed-demo`
> for demo products, orders spread over the last 90 days, and a kanban board
> with sample automations. Add `--fresh` to reseed.

**1. Install and migrate** (see above).

**2. Create a product with a price and stock.** Do this in `php artisan tinker` or a seeder:

```php
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;

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

**4. Place the order from the cart** (`$token` is the cart token from step 3):

```php
use ArtisanPackUI\Ecommerce\Contracts\OrderNumberGenerator;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;

$cart     = Cart::query()->where( 'token', $token )->with( 'items.product' )->firstOrFail();
$currency = $cart->currency;

$order = new Order( [
    'email'                   => $cart->email,
    'system_status'           => 'pending',
    'payment_status'          => 'pending',
    'fulfillment_status'      => 'unfulfilled',
    'currency'                => $currency,
    'base_currency'           => $currency,
    'fx_rate_to_base_e8'      => 100_000_000, // 1.0: the cart currency is the base currency
    'subtotal_amount'         => $cart->subtotal_amount, 'subtotal_currency' => $currency,
    'discount_amount'         => $cart->discount_amount, 'discount_currency' => $currency,
    'tax_amount'              => $cart->tax_amount,      'tax_currency'      => $currency,
    'shipping_amount'         => $cart->shipping_amount, 'shipping_currency' => $currency,
    'total_amount'            => $cart->total_amount,    'total_currency'    => $currency,
    'total_refunded_amount'   => 0,                      'total_refunded_currency' => $currency,
    'placed_at'               => now(),
] );
$order->order_number = app( OrderNumberGenerator::class )->generate( $order );
$order->save();

$types = app( ProductTypeRegistry::class );

foreach ( $cart->items as $item ) {
    OrderItem::query()->create( [
        'order_id'            => $order->id,
        'product_id'          => $item->product_id,
        'product_variant_id'  => $item->product_variant_id,
        'product_snapshot'    => $types->get( $item->product->type )->buildOrderSnapshot( $item ),
        'quantity'            => $item->quantity,
        'unit_price_amount'   => $item->unit_price_amount, 'unit_price_currency' => $currency,
        'discount_currency'   => $currency,
        'tax_currency'        => $currency,
        'shipping_currency'   => $currency,
        'total_amount'        => $item->line_total_amount, 'total_currency' => $currency,
        'fulfillment_status'  => 'unfulfilled',
    ] );
}

// Lets the engine react: order-confirmation email, kanban routing, and so on.
doAction( 'ap.ecommerce.order.placed', $order->fresh() );
```

**5. Take payment (optional) and move the order along.** With Stripe enabled
(`ECOMMERCE_STRIPE_ENABLED=true` plus keys), set
`$order->payment_gateway_key = 'stripe'`, save the order, and call
`app( \ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator::class )->finalize( $order, $cart, $shippingAddress )`.
That runs authorize → fraud assessment → capture and fires `PaymentSucceeded`
or `PaymentFailed`. Without a gateway, advance the order through the state
machine directly:

```php
app( \ArtisanPackUI\Ecommerce\Services\OrderStatusMachine::class )->transition( $order, 'processing' );
```

**6. See it in the admin API.** Create a Sanctum token for an admin user and call:

```bash
curl -s "$BASE/orders?include=items" -H "Authorization: Bearer $ADMIN_TOKEN"
```

Next steps: [HTTP API](docs/api.md) · [hooks](docs/hooks.md) · [events](docs/events.md) · [contracts](docs/contracts.md).

## Artisan commands

| Command | Purpose | Scheduled |
|---|---|---|
| `ecommerce:release-expired-reservations` | Release inventory reservations past their TTL | every minute |
| `ecommerce:retry-webhook-deliveries {--limit=500}` | Re-queue outbound webhook deliveries that are due | every minute |
| `ecommerce:prune-idempotency-records` | Delete expired idempotency records | hourly |
| `ecommerce:audit-order-status` | Report orders whose status and sub-status have drifted apart | daily 02:15 |
| `ecommerce:generate-openapi {--output=} {--stdout}` | Write the OpenAPI 3.1 spec | |
| `ecommerce:lint:pci-columns {--path=*}` | CI lint: fail on PCI-sensitive column names in migrations | |
| `ecommerce:lint:translations` | CI lint: fail on user-facing strings not wrapped in `__()` | |
| `ecommerce:seed-demo {--fresh} {--products=50} {--orders=200} {--seed=} {--force}` | Seed a demo catalog, orders, and a kanban board | |
| `ecommerce:satellite:uninstall {package} {--purge} {--force}` | Deregister a satellite, keeping its data unless `--purge` is given | |
| `ecommerce:satellite:reinstall {package}` | Re-attach a previously uninstalled satellite | |
| `ecommerce:satellite:audit {--json} {--fail-on-orphans}` | List orphaned satellite tables and columns | |
| `ecommerce:verify-satellite {--sign} {--record} {--check-signature}` | Run the contract suites against a satellite and write a (signed) verification report | |

Scheduled commands register themselves. You only need the Laravel scheduler running.

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
`fr`, and `de` catalogues and formats money per locale with `NumberFormatter`.
See [docs/localization.md](docs/localization.md).

## Documentation

| Topic | Doc |
|---|---|
| HTTP API: REST routes, errors, idempotency, rate limits, pagination | [docs/api.md](docs/api.md) |
| API authentication and abilities | [docs/api-auth.md](docs/api-auth.md) |
| GraphQL | [docs/graphql.md](docs/graphql.md) |
| OpenAPI generation | [docs/openapi.md](docs/openapi.md) |
| Hooks (actions and filters) | [docs/hooks.md](docs/hooks.md) (design spec: [docs/hooks-spec.md](docs/hooks-spec.md)) |
| Laravel events | [docs/events.md](docs/events.md) |
| Contracts and contract-test suites | [docs/contracts.md](docs/contracts.md) |
| Satellite directory | [docs/satellites.md](docs/satellites.md) |
| Satellite contract verification | [docs/satellite-verification.md](docs/satellite-verification.md) |
| Satellite lifecycle (install and uninstall) | [docs/satellite-lifecycle.md](docs/satellite-lifecycle.md) |
| Localization | [docs/localization.md](docs/localization.md) |
| Outbound webhooks | [docs/webhooks.md](docs/webhooks.md) |
| Kanban | [docs/kanban.md](docs/kanban.md) |
| Notifications | [docs/notifications.md](docs/notifications.md) |
| Reviews | [docs/reviews.md](docs/reviews.md) |
| Digital delivery and licenses | [docs/digital-delivery.md](docs/digital-delivery.md) |
| Search | [docs/search.md](docs/search.md) |
| PCI column lint | [docs/pci-column-lint.md](docs/pci-column-lint.md) |
| Upgrade guide | [docs/upgrade-guide.md](docs/upgrade-guide.md) |

## Contributing

As an open source project, this package is open to contributions from anyone.
Please [read through the contributing guidelines](CONTRIBUTING.md) to learn
more about how you can contribute to this project.
