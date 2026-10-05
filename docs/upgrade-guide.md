# Upgrade guide

This guide lists every breaking change between releases of
`artisanpack-ui/ecommerce` and what you need to do about each one. Minor and
patch releases don't need any action; they're listed in
[CHANGELOG.md](../CHANGELOG.md).

What counts as a breaking change: a change to a public contract signature
([contracts.md](contracts.md)), a hook name or its arguments
([hooks.md](hooks.md)), an event's properties ([events.md](events.md)), a
REST/GraphQL request or response shape ([api.md](api.md)), a config key, or a
migration that changes an existing column.

## 1.0.0

First stable release. A fresh install needs nothing from this section.

If you ran a pre-release build (a `release/1.0` or `dev-*` checkout before the
1.0.0 tag), read on. Pre-release builds made no compatibility promise, and
several changes landed just before the tag.

**Estimated effort:** medium for pre-release installs; none for new ones.

### High-impact changes

#### Migrations were edited in place, and every table has the `ecommerce_` prefix

**Affects:** anyone who ran the engine's migrations from a pre-release build.

**What changed:** The engine's migrations were edited rather than followed by
new ones. Every engine table is now prefixed with `ecommerce_`
(`products` → `ecommerce_products`, `orders` → `ecommerce_orders`, and so on),
and the migration files were renamed to match. The separate
`add_subject_columns_to_webhook_deliveries_table` migration was folded into
the create migration. No migration upgrades a pre-release schema.

**What to do:** Treat a pre-release database as disposable. On a development
or staging database, drop the engine's old tables, remove their rows from the
`migrations` table, and run `php artisan migrate`, or run
`php artisan migrate:fresh` if the database holds nothing else. Re-seed with
`php artisan ecommerce:seed-demo --fresh` if you need sample data. If you have
pre-release data you must keep, export it first and import it into the new
tables.

Update any raw queries, `DB::table()` calls, or reporting SQL in your app that
used the old table names.

#### `NotificationTemplate` takes a locale

**Affects:** satellites and apps that implement `Contracts\NotificationTemplate`.

**What changed:** `defaultSubject()` and `defaultBody()` take an optional
locale, so templates can ship their default text in more than one language.

**What to do:**

```php
// before
public function defaultSubject(): ?string;
public function defaultBody(): string;

// after
public function defaultSubject( ?string $locale = null ): ?string;
public function defaultBody( ?string $locale = null ): string;
```

A `null` locale means the current app locale. See
[notifications.md](notifications.md) and [localization.md](localization.md).

#### `PaymentGateway` gained a method and a parameter

**Affects:** payment gateway satellites.

**What changed:** Checkout now resumes the session the shopper confirmed
instead of creating a new one, and refunds pass context through to the
gateway.

**What to do:** Implement `retrievePaymentSession( string $reference ):
PaymentSession`, and add the new parameter to `refund()`:

```php
// before
public function refund( Order $order, Money $amount, ?string $reason = null ): RefundResult;

// after
public function refund( Order $order, Money $amount, ?string $reason = null, array $context = [] ): RefundResult;
```

Gateways whose payment step runs in the browser should also implement
`RendersClientPayment` (see [checkout.md](checkout.md#client-rendering)).

### Medium-impact changes

#### New config keys

**Affects:** apps that published `config/artisanpack/ecommerce.php` before
1.0.0.

**What changed:** Laravel merges a package's config one level deep. New
top-level sections (`cart`, `promotions`, `store`, `inventory`, `schedule`,
`retention`) come from the package. A section you already published, such as
`checkout`, replaces the package's whole section, so new keys inside it fall
back to the defaults in code until you add them. A published list, such as
`webhooks.events`, replaces the package's list.

**What to do:** Compare your published file with the package's and copy over
what you need. The new keys are:

| Key | Default |
|---|---|
| `currency.enabled`, `currency.cookie`, `currency.session_key` | `ECOMMERCE_CURRENCIES` (empty), `ecommerce_currency`, `ecommerce.currency` |
| `cart.max_lines`, `cart.ttl_days`, `cart.cookie`, `cart.cookie_lifetime`, `cart.merge_on_login`, `cart.abandoned_after_minutes` | 100, 30, `ecommerce_cart`, 43,200, `true`, 60 |
| `promotions.verbose_coupon_errors` | `false` |
| `checkout.guest_checkout`, `checkout.account_creation`, `checkout.reconcile_after_minutes` | `allowed`, `true`, 15 |
| `checkout.order_view_url`, `checkout.order_view_ttl_days` | unset, 90 |
| `checkout.guest_lookup.*` | 5 failures per order, 20 per IP, 15-minute lockout |
| `fulfillment.allow_unpaid_shipments` | `false` |
| `rate_limits.webhook.inbound.per_ip`, `rate_limits.lookup.attempt`, `rate_limits.notifications.unsubscribe` | 120, 30, 30 |
| `gateways.stripe.appearance` | `[]` |
| `fraud.fail_open` | `false` |
| `store.country` | `US` |
| `api.signature_cache_store`, `api.catalog_max_age` | default cache store, 60 |
| `webhooks.inbound_max_bytes` | 524,288 |
| `search.provider` | `default` |
| `graphql.introspection` | `null` (follows the environment) |
| `reviews.require_purchase`, `reviews.allow_multiple`, `reviews.max_media` | `false`, `false`, 5 |
| `notifications.preferences_url` | unset |
| `localization.supported_locales` | `en`, `es`, `fr`, `de` |
| `inventory.show_quantity` | `false` |
| `schedule.enabled`, `schedule.tasks` | `true`, see [operations.md](operations.md#scheduler) |
| `retention.*` | 90 / 90 / 365 / 365 days |

`api.middleware` and `graphql.middleware` gained `ecommerce.locale`. The engine
adds it even when your published list doesn't have it, so no change is needed.

#### Webhook events

**Affects:** apps with a published config that subscribe to outbound
webhooks.

**What changed:** `webhooks.events` lists the event classes delivered as
webhooks. A published list replaces the engine's, so an old file silently
leaves out the new events.

**What to do:** Add these classes to `webhooks.events`:

```php
ArtisanPackUI\Ecommerce\Events\CartAbandoned::class,
ArtisanPackUI\Ecommerce\Events\OrderPlaced::class,
ArtisanPackUI\Ecommerce\Events\CartCompleted::class,
ArtisanPackUI\Ecommerce\Events\CouponRedeemed::class,
ArtisanPackUI\Ecommerce\Events\PromotionApplied::class,
ArtisanPackUI\Ecommerce\Events\ShipmentCreated::class,
ArtisanPackUI\Ecommerce\Events\ShipmentDelivered::class,
ArtisanPackUI\Ecommerce\Events\OrderFulfilled::class,
ArtisanPackUI\Ecommerce\Events\CustomerRegistered::class,
ArtisanPackUI\Ecommerce\Events\CustomerUpdated::class,
ArtisanPackUI\Ecommerce\Events\ProductStockLow::class,
ArtisanPackUI\Ecommerce\Events\ProductOutOfStock::class,
```

See [webhooks.md](webhooks.md).

#### One review per customer by default

**Affects:** stores that accept product reviews.

**What changed:** A customer can now leave one live review per product.
Rejected and spam reviews don't count. A second submission is refused with the
reason `already-reviewed`, and `GET products/{product}/reviews/eligibility`
reports it.

**What to do:** Nothing, if one review per customer is what you want. To allow
more, set `reviews.allow_multiple` to `true`
(`ECOMMERCE_REVIEWS_ALLOW_MULTIPLE`). See [reviews.md](reviews.md).

#### Guest order links

**Affects:** stores with guest checkout.

**What changed:** Guest order confirmations now carry a signed link to the
order (`Order.view_url`), served by the new `GET order-views/{token}`
endpoint. Unless you set `checkout.order_view_url`, the link points at that
REST endpoint, which returns JSON.

**What to do:** Set `checkout.order_view_url` to your storefront's order page,
with a `{token}` placeholder (`https://shop.test/order/{token}`), and have that
page load the order from `order-views/{token}`. See
[customers.md](customers.md).

### Database

- Run `php artisan migrate` after upgrading, as after every release. When
  cms-framework is installed, this also syncs the engine's permissions.

---

<!--
Template for future entries. Copy it above the 1.0.0 section, newest first.

## Upgrading from X.Y to X+1.0

**Estimated effort:** low | medium | high

### High-impact changes

#### <Short title>

**Affects:** who is affected (store owners, satellite authors, API clients).

**What changed:** …

**What to do:**

```php
// before
// after
```

### Medium-impact changes

### Low-impact changes

### Database

- New migrations: run `php artisan migrate`. Note any long-running or locking migrations here.

### Deprecations

- `old_name` → `new_name` (removed in X+2.0).
-->
