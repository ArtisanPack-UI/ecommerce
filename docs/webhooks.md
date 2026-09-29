# Outbound webhooks

Third-party integrations (Zapier, an ERP, a fulfilment service) subscribe to
engine events and get a signed `POST` each time one happens (engine spec §8).
Every attempt is recorded in a retry ledger. Failed deliveries back off
exponentially, and a subscription that keeps failing is switched off.

## Managing subscriptions

Over REST (admin; `webhookSubscription.*` abilities):

| Method | Path | |
|---|---|---|
| `GET` | `admin/webhook-subscriptions?include=deliveries` | List (with the 20 most recent deliveries each) |
| `POST` | `admin/webhook-subscriptions` | Create. **The response is the only one that shows `secret`.** A retry with the same `Idempotency-Key` replays the response without it. |
| `PATCH` | `admin/webhook-subscriptions/{subscription}` | Update. Setting `is_active: true` resets the failure streak. |
| `DELETE` | `admin/webhook-subscriptions/{subscription}` | Delete (with its deliveries) |
| `POST` | `admin/webhook-subscriptions/{subscription}/replay/{delivery}` | Re-send a past delivery as a new ledger row. `422` while the subscription is disabled: re-enable it first. |

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
delivered, named after their class (`OrderRefunded` → `order.refunded`):

| Event | Wire name |
|---|---|
| `OrderStatusChanged` | `order.status.changed` |
| `OrderSubstatusChanged` | `order.substatus.changed` |
| `OrderEdited` | `order.edited` |
| `OrderRefunded` | `order.refunded` |
| `PaymentSucceeded` | `payment.succeeded` |
| `PaymentFailed` | `payment.failed` |
| `PaymentRefunded` | `payment.refunded` |
| `FraudBlocked` | `fraud.blocked` |

`order.placed` joins the list with the checkout service that fires `OrderPlaced`.
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

`data` holds the event's public properties. Models are rendered as their REST
resources, so they pick up `ap.ecommerce.api.resource.*` filters. Admin-only
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

Deliveries are sent by the queued `DeliverWebhookJob`. The
`ecommerce:retry-webhook-deliveries` command (scheduled every minute) queues
retries that are due. It also picks up any delivery whose job was lost, once
`webhooks.claim_seconds` has passed. Each queued job carries the claim it was
queued under, so a job left over from an earlier claim skips instead of sending
ahead of the backoff schedule.

Fan-out happens after the transaction that fired the event commits, and a
failure in it is reported rather than thrown, so a webhook problem never rolls
back the order or refund that triggered it.

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
`allow_private_hosts`, `include_admin_fields`, `connection`, `queue`. See the published config for details.
