# Webhooks

**Outbound:** third-party integrations (Zapier, an ERP, a fulfilment service)
subscribe to engine events and get a signed `POST` each time one happens
(engine spec §8). Every attempt is recorded in a retry ledger. Failed
deliveries back off exponentially, and a subscription that keeps failing is
switched off.

**Inbound:** payment providers post their own events to
`POST /ecommerce/webhooks/{provider}`. See
[Inbound provider webhooks](#inbound-provider-webhooks).

## Managing subscriptions

Over REST (admin; `webhookSubscription.*` abilities):

| Method | Path | |
|---|---|---|
| `GET` | `admin/webhook-subscriptions?include=deliveries` | List (with the 20 most recent deliveries each) |
| `POST` | `admin/webhook-subscriptions` | Create. **The response is the only one that shows `secret`.** A retry with the same `Idempotency-Key` replays the response without it. |
| `PATCH` | `admin/webhook-subscriptions/{subscription}` | Update. Setting `is_active: true` resets the failure streak. |
| `DELETE` | `admin/webhook-subscriptions/{subscription}` | Delete (with its deliveries) |
| `GET` | `admin/webhook-subscriptions/{subscription}/deliveries` | The delivery ledger, without payloads. `filter[event]`, `filter[status]` (`pending`, `retrying`, `failed`, `delivered`); sort by `id` or `created_at`. |
| `GET` | `admin/webhook-subscriptions/{subscription}/deliveries/{delivery}` | One delivery, with its payload and the receiver's response |
| `POST` | `admin/webhook-subscriptions/{subscription}/replay/{delivery}` | Re-send a past delivery as a new ledger row. `422` while the subscription is disabled: re-enable it first. |
| `POST` | `admin/webhook-subscriptions/{subscription}/replay-parked` | Put the subscription's [parked](#retries-and-auto-disable) deliveries back on the retry schedule. `202 { "data": { "requeued": 3 } }`. `422` while the subscription is disabled. |

```json
POST /api/ecommerce/v1/admin/webhook-subscriptions
Idempotency-Key: 5f0c…

{ "name": "Zapier", "url": "https://hooks.zapier.com/…", "events": ["order.refunded", "order.status.changed"] }
```

`events` holds wire event names, or `*` for every event. `url` must be
`https://` unless `webhooks.allow_insecure_urls` is on (for local development),
and its host must resolve to a **public** address. Loopback, private (RFC 1918 /
IPv6 ULA), link-local (including the cloud metadata address), and CGNAT targets
are rejected, which keeps a subscription from probing the store's own network.
The check runs again before every delivery, and the connection is pinned to the
vetted address, so a DNS change after subscribing (rebinding) can't redirect it.
NAT64 and 6to4 addresses, which can reach private IPv4 hosts through a
translator, are rejected too. Set `webhooks.allow_private_hosts` for local
development or receivers on a private network.

Address pinning needs Guzzle's curl handler. If the app sends outbound HTTP
through a proxy (`HTTP_PROXY` / `HTTPS_PROXY`), the proxy resolves the host
itself, so enforce the same egress rules on the proxy.
A `secret` of at least 16 characters may be supplied; otherwise one is generated
(`whsec_…`). Secrets are encrypted at rest. The same operations exist as GraphQL
mutations (`createWebhookSubscription`, …).

In code: `app( WebhookSubscriptionService::class )->create( [...] )`.

## Events

The domain events listed in `artisanpack.ecommerce.webhooks.events` are
delivered, named after their class (`Str::snake( class_basename( $event ), '.' )`,
so `OrderRefunded` → `order.refunded`). The shipped list:

| Event | Wire name |
|---|---|
| `CartAbandoned` | `cart.abandoned` |
| `OrderPlaced` | `order.placed` |
| `CartCompleted` | `cart.completed` |
| `CouponRedeemed` | `coupon.redeemed` |
| `PromotionApplied` | `promotion.applied` |
| `OrderStatusChanged` | `order.status.changed` |
| `OrderSubstatusChanged` | `order.substatus.changed` |
| `OrderCancelled` | `order.cancelled` |
| `OrderEdited` | `order.edited` |
| `OrderRefunded` | `order.refunded` |
| `PaymentSucceeded` | `payment.succeeded` |
| `PaymentFailed` | `payment.failed` |
| `PaymentRefunded` | `payment.refunded` |
| `FraudBlocked` | `fraud.blocked` |
| `ShipmentCreated` | `shipment.created` |
| `ShipmentDelivered` | `shipment.delivered` |
| `OrderFulfilled` | `order.fulfilled` |
| `CustomerRegistered` | `customer.registered` |
| `CustomerUpdated` | `customer.updated` |
| `ProductStockLow` | `product.stock.low` |
| `ProductOutOfStock` | `product.out.of.stock` |

Any other class in [events.md](events.md) can be added to the list. Its
payload is built the same way.

> **Published config.** The list is read from your config at boot, and
> Laravel merges package config one level deep, so a published
> `config/artisanpack/ecommerce.php` replaces the whole `webhooks` array.
> If you published it before 1.0.0, add the new event classes (everything
> above except the order, payment, and fraud events) to your copy, or they
> are never delivered.

Satellites deliver their own events directly:

```php
app( ArtisanPackUI\Ecommerce\Services\WebhookDispatcher::class )
    ->dispatch( 'subscription.renewed', [ 'subscription_id' => $subscription->id ] );
```

## Payload and headers

```http
POST {subscription url}
Content-Type: application/json
X-ArtisanPack-Signature: t=1790000000, v1=5d41402abc4b2a76b9719d911017c592…
X-ArtisanPack-Event: order.refunded
X-ArtisanPack-Delivery: 1234
X-Request-Id: 8f7d…

{
  "id": "0b6c…",
  "event": "order.refunded",
  "created_at": "2026-09-28T12:00:00+00:00",
  "data": {
    "order":  { "id": 42, "type": "order", "order_number": "A1B2C3D4", … },
    "refund": { "id": 7, "type": "refund", "amount": { "amount": 1500, "currency": "USD" }, … }
  }
}
```

The envelope is `id` (a UUID per event, shared by every subscription's
delivery), `event` (the wire name), `created_at`, and `data`.

`data` holds the event's public properties, keyed in `snake_case`. Models are
rendered as their REST resources, so they pick up `ap.ecommerce.api.resource.*`
filters. `Money` values become `{ "amount": <minor units>, "currency": "USD" }`,
enums their backing value, and a `Throwable` `{ "type", "message" }`. Every
timestamp inside `data` is an RFC 3339 string in UTC with a `Z` suffix
(`2026-09-28T12:00:00Z`). The envelope's `created_at` is ISO 8601 in the
application timezone (`+00:00` with Laravel's default `UTC`). Admin-only
fields (payment reference, IP address, user agent, product meta, cost prices)
are left out unless `webhooks.include_admin_fields` is on. `X-ArtisanPack-Delivery` is stable across
retries of the same delivery, so use it to de-duplicate. Redirects are not
followed.

## Verifying the signature

`X-ArtisanPack-Signature: t=<unix ts>, v1=<hex(hmac_sha256(t + "." + raw body, secret))>`

Compute the HMAC over the **raw** request body, compare in constant time, and
reject timestamps more than five minutes old.

```php
use ArtisanPackUI\Ecommerce\Webhooks\WebhookSigner;

$valid = WebhookSigner::verify( $request->header( 'X-ArtisanPack-Signature' ), $request->getContent(), $secret );
```

```js
import crypto from 'node:crypto';

function verify(header, rawBody, secret, toleranceSeconds = 300) {
  const parts = Object.fromEntries(header.split(',').map((p) => p.trim().split('=')));
  if (!/^\d+$/.test(parts.t ?? '') || !parts.v1) return false;
  if (Math.abs(Date.now() / 1000 - Number(parts.t)) > toleranceSeconds) return false;
  const expected = Buffer.from(crypto.createHmac('sha256', secret).update(`${parts.t}.${rawBody}`).digest('hex'));
  const received = Buffer.from(parts.v1);
  return expected.length === received.length && crypto.timingSafeEqual(expected, received);
}
```

## Retries and auto-disable

- A **2xx** response marks the delivery delivered and resets the subscription's failure streak.
- Anything else (non-2xx, timeout, connection error) increments `attempts` and
  schedules a retry: 1m, 5m, 15m, 30m, 1h, 2h, 4h, 8h, 12h, then 24h. After
  `webhooks.max_attempts` (10) attempts the delivery is given up on.
- Every failure increments the subscription's `consecutive_failures`. At
  `webhooks.disable_after_failures` (10) the subscription is switched off and
  `WebhookSubscriptionDisabled` is dispatched. Listen for that event to notify
  an operator. Re-enabling the subscription (`is_active: true`) resets the streak.
- New events are only recorded for active subscriptions. A delivery whose
  subscription is inactive when its attempt comes up is **parked**: taken off
  the retry schedule, undelivered, with attempts left. Re-enabling the
  subscription doesn't send parked deliveries on its own. Call
  `POST …/replay-parked` (or `WebhookSubscriptionService::replayParked()`) when
  the receiver should catch up.

Deliveries are sent by the queued `DeliverWebhookJob`. The
`ecommerce:retry-webhook-deliveries` command (scheduled every minute) queues
retries that are due. It also picks up any delivery whose job was lost, once
`webhooks.claim_seconds` has passed. Each queued job carries the claim it was
queued under, so a job left over from an earlier claim skips instead of sending
ahead of the backoff schedule.

Fan-out happens after the transaction that fired the event commits, and a
failure in it is reported rather than thrown, so a webhook problem never rolls
back the order or refund that triggered it.

## Retention

`ecommerce:prune-ledgers` (scheduled daily at 03:45) deletes outbound
deliveries older than `retention.webhook_deliveries_days` (default 90; `0`
keeps them forever). Only finished deliveries are pruned: delivered, or out of
attempts. Pending, retrying, and parked deliveries are kept.

When a customer is deleted, their deliveries (found through the delivery's
`order_id` and `customer_id` columns) keep their rows but have personal values
redacted from the payload, `payload_hash` recomputed, and the receiver's
stored response dropped. See [customers.md](customers.md).

## Inbound provider webhooks

`POST /ecommerce/webhooks/{provider}` sits outside the API prefix (`api` and
`ecommerce.request-id` middleware, CSRF-exempt). The request goes to
`handleWebhook()` on the `PaymentGateway` registered under `{provider}`, which
verifies the provider's signature. For Stripe, point the dashboard at
`https://your-store.test/ecommerce/webhooks/stripe`.

| Response | When | Ledger |
|---|---|---|
| `404 { code: "gateway_not_registered" }` | No gateway is registered under `{provider}` | No row |
| `413 { code: "payload_too_large" }` | The body (or its `Content-Length`) is over `webhooks.inbound_max_bytes` (default 524,288 bytes, 512 KB) | No row |
| `400 { code, message }` | The signature didn't verify | The body's SHA-256 hash, its size, and its first 1 KB only |
| `429 { code: "rate_limited" }` + `Retry-After` | The provider's verified allowance is used up | No row |
| `200 { received, event_id, type }` | A verified event. A redelivered event id adds `"duplicate": true` and fires nothing. | Full body and the parsed payload |

**Rate limits.** Every request counts against its source IP
(`rate_limits.webhook.inbound.per_ip`, default 120/min), so junk for
made-up providers or with bad signatures is bounded per sender. Only
signature-verified requests count against the provider's own allowance
(`rate_limits.webhook.inbound.per_provider`, default 1,000/min), so
unverified traffic can't use it up.

**Ledger.** Each stored request is an `InboundWebhookDelivery` row: provider,
event id and type, `verified`, `duplicate`, error code, `payload_hash`,
`payload_size`, `payload_truncated`, the stored `payload`, `parsed`,
response status, correlation id, and `received_at`. Verified rows also carry
`session_reference`, the payment session the event is about, which links the
event to its order.

**Dispatch.** A verified, new event fires `ap.ecommerce.webhook_received`,
`ap.ecommerce.gateway.{provider}.webhook_received`, and
`ap.ecommerce.payment.webhookReceived` (see [hooks.md](hooks.md#payments-and-fraud)).
The event id is claimed in `idempotency_records` first, so a provider's
redelivery never fires twice. If a listener throws, the claim is released
and the request fails with a 5xx so the provider retries.

**Reconciliation.** When the event carries a payment outcome for a session,
the controller queues `ReconcilePaymentSession`, which settles the checkout
off the request (a shopper who closed the tab still gets their order). The
`ecommerce:reconcile-payments` command (every 15 minutes) is the safety net
for webhooks that never arrive. It asks the provider about payment sessions
quiet for `checkout.reconcile_after_minutes` (default 15; `--minutes`
overrides it) and no older than seven days, and settles the ones the
provider confirmed or cancelled.

**Retention and privacy.** `ecommerce:prune-ledgers` deletes inbound rows
older than `retention.inbound_webhooks_days` (default 90; `0` keeps them
forever). Raw provider events can hold billing names, emails, and addresses,
so deleting a customer empties the stored `payload` and `parsed` of every
inbound row about their orders' payment sessions (`session_reference`) or
containing their email. The row, hash, and size stay for the audit trail.

## Hooks and events

| Hook | Type | Payload |
|---|---|---|
| `ap.ecommerce.webhook.subscribing` | filter | `(array $attributes)`; return `null` to reject the subscription |
| `ap.ecommerce.webhook.delivering` | filter | `(array $payload, WebhookSubscription $subscription, string $event)`; runs before signing |
| `ap.ecommerce.webhook.delivered` | action | `(WebhookDelivery $delivery)` |
| `ap.ecommerce.webhook.failed` | action | `(WebhookDelivery $delivery, Throwable $reason)` |
| `ap.ecommerce.webhook.subscriptionDisabled` | action | `(WebhookSubscription $subscription)` |

Laravel events: `WebhookDelivered`, `WebhookFailed`, `WebhookSubscriptionDisabled`.

## Configuration

`artisanpack.ecommerce.webhooks`: `events`, `backoff_seconds`, `max_attempts`,
`disable_after_failures`, `timeout`, `claim_seconds`, `allow_insecure_urls`,
`allow_private_hosts`, `include_admin_fields`, `connection`, `queue`, and
`inbound_max_bytes`. Also `rate_limits.webhook.inbound.per_ip` /
`.per_provider`, and `retention.webhook_deliveries_days` /
`retention.inbound_webhooks_days`. See the published config for details.
