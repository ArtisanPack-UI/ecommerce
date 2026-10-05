# Operations

What a store needs to run the engine in production: a queue worker, the
scheduler, a few artisan commands, retention, and rate limits.

## After installing or upgrading

Run the migrations after every install and every upgrade:

```bash
php artisan migrate
```

When cms-framework is installed, the same run also syncs the engine's
permissions and the `shop-manager` role (see [permissions.md](permissions.md)).

## Queue

Run a queue worker. These jobs are part of normal operation:

| Job | Queued when | Connection / queue |
|---|---|---|
| `Jobs\DeliverWebhookJob` | An outbound webhook event fires, and by the retry sweep | `webhooks.connection` / `webhooks.queue` |
| `Jobs\ReconcilePaymentSession` | A verified payment webhook reports an outcome; it places the order if the shopper never came back | `webhooks.connection` / `webhooks.queue` |
| `Jobs\SendKanbanAutomationWebhookJob` | A kanban automation calls a webhook | `webhooks.connection` / `webhooks.queue` |
| Engine notifications (`EcommerceNotification`) | Order, shipment, and account emails | Laravel's default for queued notifications |

Without a worker, outbound webhooks are never delivered, and a checkout the
shopper abandoned after paying is only settled by the
`ecommerce:reconcile-payments` poll. Leave `webhooks.connection` and
`webhooks.queue` unset to use the app's default connection and queue.

## Scheduler

The engine adds its maintenance commands to the host's scheduler. Run the
scheduler every minute, as for any Laravel app:

```cron
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

| Command | Default schedule | What it does |
|---|---|---|
| `ecommerce:release-expired-reservations` | every minute | Frees stock holds past `expires_at` |
| `ecommerce:retry-webhook-deliveries` | every minute | Queues outbound deliveries whose retry time has come |
| `ecommerce:flag-abandoned-carts` | every 5 minutes | Flags carts left in checkout, and fires `CartAbandoned` |
| `ecommerce:reconcile-payments` | every 15 minutes | Settles checkouts the provider confirmed or cancelled without the storefront hearing about it |
| `ecommerce:prune-idempotency-records` | hourly | Deletes expired idempotency records |
| `ecommerce:audit-order-status` | daily, 02:15 | Reports drift between orders' system status and their sub-statuses |
| `ecommerce:prune-carts` | daily, 03:30 | Deletes expired carts, and converted carts older than `cart.ttl_days` |
| `ecommerce:prune-ledgers` | daily, 03:45 | Deletes ledger rows past their retention |
| `ecommerce:refresh-fx-rates` | daily, 05:30 | Refreshes exchange rates for every enabled currency |

Every task runs with `onOneServer()`, `withoutOverlapping()`, and
`runInBackground()`. `onOneServer()` needs a cache store that supports atomic
locks, shared by every server: Redis, Memcached, database, or DynamoDB. The
`file` and `array` stores only work on a single server.

The schedule is set in `artisanpack.ecommerce.schedule`:

```php
'schedule' => [
    'enabled' => env( 'ECOMMERCE_SCHEDULE_ENABLED', true ),
    'tasks'   => [
        'ecommerce:reconcile-payments' => '*/5 * * * *', // run more often
        'ecommerce:refresh-fx-rates'   => null,          // stop scheduling it
    ],
],
```

- `enabled` — `false` schedules nothing. Run the commands yourself from your
  own scheduler or cron.
- `tasks` — A cron expression per command, merged over the shipped defaults.
  A command you leave out keeps its default. `null` or an empty string stops
  scheduling that command.

## Commands

| Command | Options | What it does |
|---|---|---|
| `ecommerce:release-expired-reservations` | | Deletes `inventory_reservations` past `expires_at`, frees the reserved stock, and fires `ap.ecommerce.inventory.reservationReleased` per row |
| `ecommerce:retry-webhook-deliveries` | `--limit=500` most deliveries to queue in one run | Queues outbound deliveries whose retry time has come |
| `ecommerce:flag-abandoned-carts` | | Flags carts in checkout, with an email, untouched for `cart.abandoned_after_minutes` |
| `ecommerce:reconcile-payments` | `--minutes=` quiet period (default `checkout.reconcile_after_minutes`) | Asks the provider how quiet payment sessions ended, and finalizes or releases them. Looks back 7 days. Exits non-zero when any session errored |
| `ecommerce:prune-idempotency-records` | | Deletes expired `idempotency_records` rows |
| `ecommerce:audit-order-status` | | Reports drift between `orders.system_status` and sub-statuses |
| `ecommerce:prune-carts` | | Deletes expired carts and old converted carts, releasing their stock holds first |
| `ecommerce:prune-ledgers` | | Deletes ledger rows older than their `retention.*_days`, in batches |
| `ecommerce:refresh-fx-rates` | `--currency=*` only these currencies | Fetches and caches exchange rates from the base currency |
| `ecommerce:sync-permissions` | | Registers the abilities as cms-framework permissions and the `shop-manager` role |
| `ecommerce:seed-demo` | `--fresh`, `--products=50`, `--orders=200`, `--seed=`, `--force`, `--i-understand-this-deletes-production-data` | Seeds a demo store (see below) |
| `ecommerce:generate-openapi` | `--output=` file (default `storage/app/ecommerce-openapi.json`), `--stdout` | Writes the OpenAPI 3.1 spec. See [openapi.md](openapi.md) |
| `ecommerce:lint:pci-columns` | `--path=*` extra migration directories | Fails if a migration declares a PCI-sensitive column. See [pci-column-lint.md](pci-column-lint.md) |
| `ecommerce:lint:translations` | `--path=*`, `--lang=`, `--no-engine`, `--sync` | Fails on bare user-facing strings or missing catalogue keys. See [localization.md](localization.md) |
| `ecommerce:satellite:audit` | `--json`, `--fail-on-orphans` | Lists satellites and the tables and columns removed satellites left behind |
| `ecommerce:satellite:uninstall {package}` | `--purge` also roll back its migrations, `--force` skip the prompt | Deregisters a satellite. Data is kept unless `--purge` |
| `ecommerce:satellite:reinstall {package}` | | Re-attaches an uninstalled satellite |
| `ecommerce:verify-satellite` | `--path=`, `--output=`, `--package-version=`, `--namespace=*`, `--tests=*`, `--provider=*`, `--no-run`, `--allow-empty`, `--sign`, `--signature=`, `--check-signature`, `--public-key=`, `--record` | Runs the engine's contract suites against a satellite. See [satellite-verification.md](satellite-verification.md) |

### Demo data

`ecommerce:seed-demo` fills a store with products, customers, about 200 orders,
and a kanban board. It refuses to run on a store that already has data unless
you pass `--fresh`, which empties the engine's tables first. It asks for
confirmation unless you pass `--force`.

In production it has two extra guards:

- It refuses to run at all without `--force`.
- `--fresh` also needs `--i-understand-this-deletes-production-data`.

## Retention

`ecommerce:prune-ledgers` deletes ledger rows older than their retention, in
days. `0` keeps a ledger forever.

| Key | Default | Rows |
|---|---|---|
| `retention.inbound_webhooks_days` | 90 | Provider webhooks received. Raw payloads may hold customer data |
| `retention.webhook_deliveries_days` | 90 | Outbound deliveries that were delivered or ran out of attempts. Pending, retrying, and parked deliveries are never pruned |
| `retention.activity_log_days` | 365 | Admin activity entries |
| `retention.download_events_days` | 365 | Digital download events (IP, user agent) |

Expired idempotency records are pruned hourly by
`ecommerce:prune-idempotency-records`, and old carts daily by
`ecommerce:prune-carts`.

## Inbound webhooks

Payment providers post to `POST /ecommerce/webhooks/{provider}`. Before
anything is stored:

- An unknown `{provider}` is a 404.
- A body larger than `webhooks.inbound_max_bytes` (default 524,288 bytes,
  512 KB) is a 413. Both the `Content-Length` header and the actual body are
  checked.

An unverified request (a bad signature) is a 400. The ledger keeps only its
hash, its size, and the first kilobyte. A verified event is de-duplicated on
the provider's event id and recorded in full.

Two rate limits apply. Every request counts against `ecommerce.webhook.inbound`
(per IP). Only verified requests count against `ecommerce.webhook.verified`
(per provider), so junk traffic can't use up a provider's allowance.

## Rate limits

Every route carries a named policy. Policies with several buckets check every
bucket before spending any. Override a bucket in
`artisanpack.ecommerce.rate_limits.{policy}.{bucket}` (requests per window) or
with its env var. The window is fixed. A value of `0` or less falls back to the
default, so a limit can't be switched off this way.

| Policy | Buckets (default) | Used by |
|---|---|---|
| `ecommerce.catalog.read` | 300/min per IP | Catalog and search reads, downloads, review listing |
| `ecommerce.cart.mutate` | 60/min per cart + 300/min per IP | Cart reads and writes, checkout steps up to the payment gateway |
| `ecommerce.checkout.finalize` | 6/min per IP + 12/hour per cart | `checkout/{cart}/session`, `checkout/{cart}/finalize` |
| `ecommerce.coupon.attempt` | 10/hour per cart + 30/hour per IP | `POST carts/{cart}/coupons` |
| `ecommerce.review.submit` | 3/hour per customer + 10/hour per IP | Review submission |
| `ecommerce.claim.attempt` | `customers.claim_rate_limit` (5) per `customers.claim_rate_window_minutes` (60) per customer + 30/hour per IP (`rate_limits.claim.attempt.per_ip`) | `POST me/claims` |
| `ecommerce.lookup.attempt` | 30/min per IP | Guest order lookup and `order-views/{token}` (REST and GraphQL) |
| `ecommerce.license.validate` | 60/min per license key + 600/min per IP | License validation and deactivation |
| `ecommerce.webhook.inbound` | 120/min per IP | Every inbound provider webhook |
| `ecommerce.webhook.verified` | 1,000/min per provider (`rate_limits.webhook.inbound.per_provider`) | Verified inbound webhooks only |
| `ecommerce.notifications.unsubscribe` | 30/min per IP | Signed unsubscribe links |
| `ecommerce.admin.mutate` | 120/min per user | Admin, kanban, and `me/*` routes |
| `ecommerce.login` | 5/min per IP + 20/hour per email | Registered for storefront and admin login forms; no engine route uses it |

The 429 response format is in [api.md](api.md#rate-limits).

## Request ids

Every API request gets a correlation id. The `ecommerce.request-id`
middleware reads the incoming `X-Request-Id` header, or generates a UUID when
it is missing or invalid. It adds the id to the log context, returns it as
`X-Request-Id` on the response, and sends it on outbound webhook deliveries.
Lines on the `ecommerce` log channel carry it as `request_id`.

The id lives in a static holder (`Support\RequestContext`). The engine clears
it before and after every queued job, and at the start of every Octane request,
so a long-lived worker never reuses one request's id for the next. A queued job
does not inherit the id of the request that queued it: if the job sends an
outbound call, a new id is generated for that job.

## Production checklist

- Run `php artisan migrate` after every upgrade.
- Run a queue worker.
- Run `schedule:run` every minute, with a lock-capable cache shared by every
  server.
- Set `fraud.provider` (the default, `always-approve`, approves everything).
- Point your payment provider's webhooks at `/ecommerce/webhooks/{provider}`.
- Check the `retention.*` values against your data-retention policy.
- Set `checkout.order_view_url` to your storefront's order page.
