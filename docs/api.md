# HTTP API

The engine exposes a versioned REST API, a GraphQL endpoint, and one inbound
webhook receiver (parent plan §12–13, engine spec §9–11). This page covers the
REST surface, auth, error format, idempotency, rate limits, and pagination,
and summarizes GraphQL and OpenAPI.

| Surface | Base path | Toggle |
|---|---|---|
| REST | `/{api_prefix}/{api.version}`, which defaults to **`/api/ecommerce/v1`** | `artisanpack.ecommerce.features.rest` |
| GraphQL | `/graphql/ecommerce` (rebing's `graphql.route.prefix` + `/ecommerce`) | `artisanpack.ecommerce.features.graphql` |
| Inbound payment webhooks | `POST /ecommerce/webhooks/{provider}` | always on |

Every REST route is named `ecommerce.api.*`, for example
`ecommerce.api.carts.items.store`. Every REST response is JSON because the
`ecommerce.json` middleware forces `Accept: application/json`. Every request
gets an `X-Request-Id` correlation header. A valid inbound `X-Request-Id` (up to
128 characters) is kept, otherwise one is generated. The id is echoed on the
response and threaded into the `ecommerce` log channel.

## Versioning

The version is a path segment (`artisanpack.ecommerce.api.version`, env
`ECOMMERCE_API_VERSION`, default `v1`), and the prefix is
`artisanpack.ecommerce.api_prefix` (env `ECOMMERCE_API_PREFIX`, default
`api/ecommerce`). Breaking changes will ship under a new version segment and
will be listed in the [upgrade guide](upgrade-guide.md).

## Authentication

Full details: [api-auth.md](api-auth.md).

- **Public routes** need no credentials: catalog reads, search, downloads (the
  URL token is the credential), license validation, and review submission
  (guests may submit when allowed).
- **Cart routes** use the 40-character cart **token** in the URL as the
  credential. Treat it like a session secret.
- **Admin and `me/*` routes** run the `artisanpack.ecommerce.api.auth_middleware`
  stack, which defaults to `ecommerce.service-signature` then `auth:sanctum`.
  - A **Sanctum token** is sent as `Authorization: Bearer {token}`. Token
    abilities narrow what the user may do: `ecommerce:admin`,
    `ecommerce:storefront`, or per-resource scopes such as `ecommerce:orders.read`
    and `ecommerce:tax-rates.write`.
  - **Sanctum SPA cookies** work for a first-party storefront on the same domain.
  - A **signed service request** is sent as
    `Authorization: Signature keyId="…",algorithm="hmac-sha256",…`, using a
    keyId from `artisanpack.ecommerce.api.services`.
- Admin routes also check an **ability**, `ecommerce.{resource}.{action}`
  (the `ecommerce.can:{resource},{action}` middleware). Abilities are
  default-deny: a Gate for the exact ability decides, otherwise the umbrella
  `ecommerce.admin` Gate decides, otherwise the request is refused. The
  `ap.ecommerce.abilities.{resource}.{action}` filter can then override that
  decision, but a Sanctum token's own abilities are checked afterwards: the
  filter can't grant an action the caller's token doesn't carry.

## Error format

Every error the engine produces is `application/problem+json` (RFC 9457):

```json
{
  "type": "https://docs.artisanpack-ui.dev/ecommerce/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "The request payload failed validation.",
  "instance": "/api/ecommerce/v1/carts/abc…/items",
  "errors": [
    { "field": "quantity", "code": "invalid", "message": "The quantity field must be at least 1." }
  ]
}
```

`type` is `{problem_base_url}/{slug}`. Set the base with
`artisanpack.ecommerce.idempotency.problem_base_url` (rate-limit problems use
`artisanpack.ecommerce.rate_limits.problem_base_url`). `errors` only appears
when there are field-level errors.

| Status | Slug | When |
|---|---|---|
| 400 | `missing-idempotency-key` | An endpoint that requires `Idempotency-Key` was called without one |
| 400 | `oversized-idempotency-key` | The key is longer than 255 characters |
| 400 | `invalid-list-query` | Unknown `filter[…]`, `sort`, or `include`, or a badly typed filter value |
| 401 | `unauthenticated` | Missing or invalid credentials on an authenticated route |
| 403 | `forbidden` | The caller lacks the route's ability (`detail` names it) |
| 409 | `idempotency-key-conflict` | The key was reused with a different payload, or the original request is still in flight |
| 422 | `validation-failed` | FormRequest validation failed |
| 422 | a cart error code, e.g. `product-unavailable`, `quantity-limit`, `coupon-invalid` | An expected cart failure (`CartOperationException`) |
| 429 | `rate-limited` | A rate-limit policy was exceeded (see below) |

A `404` for an unknown id or cart token uses Laravel's default JSON body
(`{"message": "…"}`), not problem+json.

## Response envelope and lists

A single resource is wrapped in `data`. Each resource carries `id` and `type`
(the resource name) plus its snake_case fields. Money fields are
`{ "amount": <int minor units>, "currency": "USD" }`.

Lists use **cursor pagination**:

```text
GET /api/ecommerce/v1/products?filter[type]=simple,digital&sort=-created_at&include=prices&per_page=50
```

| Parameter | Meaning |
|---|---|
| `filter[field]=value` | Exact match. A comma-separated value becomes `IN (…)`. Only each endpoint's allow-listed fields are accepted. Typed filters (`int`, `bool`) are validated. Some filters are custom, like `filter[search]` on products. |
| `sort=a,-b` | Comma-separated. A `-` prefix sorts descending. `id` is always allowed and is added as a final tiebreaker. The default is `-id`. |
| `include=a,b` | Eager-load allow-listed relations. Admin-only relations are silently omitted for non-admin callers. |
| `per_page` | Page size. Defaults to `api.default_per_page` (25) and is capped at `api.max_per_page` (100). |
| `cursor` | The opaque cursor from `links.next` / `meta.next_cursor` |

```json
{
  "data": [ { "id": 12, "type": "product", "name": "…" } ],
  "links": { "first": null, "last": null, "prev": null, "next": "…?cursor=eyJ…" },
  "meta": { "path": "…/products", "per_page": 50, "next_cursor": "eyJ…", "prev_cursor": null }
}
```

`GET /search?q=…` is the exception. It goes through Scout and pages with
`page` / `per_page` instead of a cursor. See [search.md](search.md).

Each endpoint's filters, sorts, and includes are listed in the generated
[OpenAPI spec](openapi.md).

## Idempotency

Every mutating REST endpoint requires an **`Idempotency-Key`** header, and a
retry with the same key replays the original response instead of repeating the
side effect (engine spec §11.2). This covers cart writes, including
`POST /carts`, and every admin write except the read-only
`POST admin/notification-templates/{template}/preview`. It also covers `POST /products/{product}/reviews`
and `POST /license/validate`. On GraphQL the header is optional.

```bash
KEY=$(uuidgen)   # one fresh key per logical operation; reuse it only to retry that operation

curl -sS -X POST "https://shop.test/api/ecommerce/v1/carts" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $KEY" \
  -d '{"currency":"USD"}'

# Network blip? Send the identical request again with the SAME key:
curl -sS -i -X POST "https://shop.test/api/ecommerce/v1/carts" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $KEY" \
  -d '{"currency":"USD"}'
# → same status and body as the first call, plus `Idempotent-Replay: true`
```

How it works:

- **Scope.** A record is keyed by *(actor, endpoint, key)*. The actor is the
  signed service, the Sanctum token, the user, the session, or (for anonymous
  API calls) the client IP. The endpoint is the route name. Always generate
  keys randomly, for example UUID v4: anonymous callers behind one NAT share an
  actor scope.
- **Request fingerprint.** The fingerprint is a SHA-256 of the canonicalized
  body (key order doesn't matter), the query string, uploaded files (by
  content), and the route parameters. Reusing a key with a different
  fingerprint returns **409 `idempotency-key-conflict`**. That includes the
  same key against a different cart token.
- **Replay.** The first response is stored, whatever its status (including
  4xx). A matching retry gets back the same status, body, and headers, minus
  `Set-Cookie`, plus `Idempotent-Replay: true`. Responses that contain one-time
  secrets, like a new webhook subscription's `secret`, are stored with those
  fields redacted.
  The check runs after authentication and the ability check but before
  route-model binding, so retrying a successful `DELETE` replays its success
  instead of returning 404 for the now-missing record.
- **Concurrency.** If a duplicate arrives while the original is still
  running, it waits up to `idempotency.wait_ms` (8,000 ms, polling every
  `poll_ms` = 100 ms) for the result. If the original still hasn't finished,
  the duplicate gets **409**.
- **TTL.** Records expire after `idempotency.default_ttl_hours` (24).
  Override per endpoint by route name:
  `'ttls' => [ 'ecommerce.api.orders.refunds.store' => 72 ]`.
  `ecommerce:prune-idempotency-records` deletes expired rows hourly.
- **Keys** are trimmed and may be at most 255 characters.

## Rate limits

Every route carries one named policy, attached with
`ecommerce.rate-limit:{policy}`. Policies are registered by
`RateLimitPolicyRegistrar` (engine spec §11.3, parent plan §16.1). Compound
policies check *every* bucket before spending any. Override a limit in
`artisanpack.ecommerce.rate_limits.{policy}.{bucket}` or with the env var shown.

| Policy | Buckets (default) | Bucket key | Used by |
|---|---|---|---|
| `ecommerce.catalog.read` | 300/min per IP (`ECOMMERCE_RATE_CATALOG_READ_PER_IP`) | client IP | product reads, search, downloads |
| `ecommerce.cart.mutate` | 60/min per cart (`…_CART_MUTATE_PER_CART`) + 300/min per IP (`…_CART_MUTATE_PER_IP`) | cart token from the route, `cart_token` input, or `X-Cart-Token` header (falls back to IP); IP | `POST /carts`, cart reads and writes |
| `ecommerce.coupon.attempt` | 10/hour per cart + 30/hour per IP | as above | `POST /carts/{cart}/coupons` |
| `ecommerce.review.submit` | 3/hour per customer (guests: per IP) + 10/hour per IP | user id; IP | `POST /products/{product}/reviews` |
| `ecommerce.license.validate` | 60/min per license key (only when a key is sent) + 600/min per IP | `key` / `license_key` input; IP | `POST /license/validate` |
| `ecommerce.webhook.inbound` | 1,000/min per provider | `{provider}` route segment | `POST /ecommerce/webhooks/{provider}` |
| `ecommerce.admin.mutate` | 120/min per user (anonymous: per IP) | user id; IP | every admin and `me/*` route, reads included |
| `ecommerce.checkout.finalize` | 6/min per IP + 12/hour per cart | IP; cart token | registered for checkout surfaces; no engine route uses it yet |
| `ecommerce.login` | 5/min per IP + 20/hour per email (only when an email is sent) | IP; `email` input | registered for storefront/admin login surfaces; no engine route uses it yet |

Exceeding a limit returns:

```http
HTTP/1.1 429 Too Many Requests
Content-Type: application/problem+json
Retry-After: 42
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0

{
  "type": "https://docs.artisanpack-ui.dev/ecommerce/problems/rate-limited",
  "title": "Too many requests",
  "status": 429,
  "detail": "You have exceeded the \"ecommerce.cart.mutate\" rate limit. Retry after 42 seconds.",
  "instance": "/api/ecommerce/v1/carts/…/items",
  "policy": "ecommerce.cart.mutate",
  "retry_after": 42
}
```

Successful responses don't carry `X-RateLimit-*` headers. Every refusal is
logged as `ecommerce.rate_limit.exceeded` with the policy, route name, and a
hashed IP.

## REST routes

Paths are relative to `/api/ecommerce/v1`. **Idem.** means an
`Idempotency-Key` is required. Ability `order.refund` means the Gate ability
`ecommerce.order.refund`.

### Catalog, reviews, downloads, and licenses (public)

| Method | Path | Rate policy | Idem. |
|---|---|---|---|
| `GET` | `products` | `catalog.read` | |
| `GET` | `products/{product}` | `catalog.read` | |
| `GET` | `products/{product}/variants` | `catalog.read` | |
| `GET` | `products/{product}/reviews` | `catalog.read` | |
| `GET` | `search?q=…` | `catalog.read` | |
| `POST` | `products/{product}/reviews` (optional auth) | `review.submit` | yes |
| `GET` | `downloads/{token}` | `catalog.read` | |
| `GET` | `downloads/{token}/stream` (supports `Range`) | `catalog.read` | |
| `POST` | `license/validate` | `license.validate` | yes |

### Cart (the token is the credential)

| Method | Path | Body | Rate policy | Idem. |
|---|---|---|---|---|
| `POST` | `carts` | `{ currency?: "USD", email? }` | `cart.mutate` | yes |
| `GET` | `carts/{cart}` | | `cart.mutate` | |
| `POST` | `carts/{cart}/items` | `{ product_id, quantity (1–10000), product_variant_id?, options? }` | `cart.mutate` | yes |
| `PATCH` | `carts/{cart}/items/{item}` | `{ quantity }` (0 removes the line) | `cart.mutate` | yes |
| `DELETE` | `carts/{cart}/items/{item}` | | `cart.mutate` | yes |
| `POST` | `carts/{cart}/coupons` | `{ code }` | `coupon.attempt` | yes |
| `DELETE` | `carts/{cart}/coupons/{code}` | | `cart.mutate` | yes |

Prices are always resolved on the server. The client never sends a unit price.

### Orders, customers, and the signed-in shopper (authenticated)

| Method | Path | Ability | Idem. |
|---|---|---|---|
| `GET` | `orders` | `order.viewAny` | |
| `GET` | `orders/{order}` | `order.view` | |
| `PATCH` | `orders/{order}` | `order.update` | yes |
| `POST` | `orders/{order}/cancel` | `order.cancel` | yes |
| `POST` | `orders/{order}/refunds` | `order.refund` | yes |
| `GET` | `orders/{order}/timeline` | `order.view` | |
| `POST` | `orders/{order}/notes` | `order.update` | yes |
| `POST` | `orders/{order}/shipments` | `order.update` | yes |
| `PATCH` | `orders/{order}/shipments/{shipment}` | `order.update` | yes |
| `GET` | `customers` | `customer.viewAny` | |
| `GET` | `customers/{customer}` | `customer.view` | |
| `PATCH` | `customers/{customer}` | `customer.update` | yes |
| `DELETE` | `customers/{customer}` (delete and anonymize) | `customer.delete` | yes |
| `POST` | `customers/{customer}/addresses` | `customer.update` | yes |
| `PATCH` / `DELETE` | `customers/{customer}/addresses/{address}` | `customer.update` | yes |
| `GET` | `customers/{customer}/notes` | `customer.view` | |
| `POST` | `customers/{customer}/notes` | `customer.update` | yes |
| `DELETE` | `customers/{customer}/notes/{note}` | `customer.update` | yes |
| `GET` | `me/notification-preferences` | the signed-in user | |
| `PATCH` | `me/notification-preferences` | the signed-in user | yes |

All of these use the `admin.mutate` rate policy.

### Kanban (`kanban/…`, authenticated, `admin.mutate`)

| Method | Path | Ability | Idem. |
|---|---|---|---|
| `GET` / `POST` | `kanban/boards` | `kanbanBoard.viewAny` / `kanbanBoard.create` | POST |
| `GET` / `PATCH` / `DELETE` | `kanban/boards/{board}` | `kanbanBoard.view` / `.update` / `.delete` | PATCH, DELETE |
| `GET` | `kanban/boards/{board}/cards` | `kanbanBoard.view` | |
| `POST` | `kanban/cards/{order}/move` | `kanbanCard.move` | yes |
| `POST` | `kanban/boards/{board}/columns` | `kanbanBoard.update` | yes |
| `PATCH` / `DELETE` | `kanban/columns/{column}` | `kanbanBoard.update` | yes |
| `POST` | `kanban/boards/{board}/automations` | `kanbanBoard.update` | yes |
| `PATCH` / `DELETE` | `kanban/automations/{automation}` | `kanbanBoard.update` | yes |
| `POST` / `DELETE` | `kanban/boards/{board}/assignments/{order}` | `kanbanCard.move` | yes |
| `GET` | `kanban/widgets`, `kanban/triggers` | `kanbanBoard.viewAny` | |

### Admin (`admin/…`, authenticated, `admin.mutate`)

| Method | Path | Ability | Idem. |
|---|---|---|---|
| `GET` | `admin/products`, `admin/products/{product}` (any status) | `product.viewAny` / `product.view` | |
| `POST` | `admin/products` | `product.create` | yes |
| `PATCH` / `DELETE` | `admin/products/{product}` | `product.update` / `product.delete` | yes |
| `POST` | `admin/products/{product}/variants` | `product.update` | yes |
| `PATCH` / `DELETE` | `admin/products/{product}/variants/{variant}` | `product.update` | yes |
| `POST` | `admin/products/{product}/variants/generate`, `…/variants/reorder` | `product.update` | yes |
| `POST` | `admin/products/{product}/prices` (upsert; `product_variant_id` targets a variant) | `product.update` | yes |
| `PATCH` / `DELETE` | `admin/products/{product}/prices/{price}` | `product.update` | yes |
| `POST` | `admin/products/{product}/images`, `…/images/reorder` | `product.update` | yes |
| `PATCH` / `DELETE` | `admin/products/{product}/images/{image}` | `product.update` | yes |
| `POST` | `admin/products/{product}/attributes` | `product.update` | yes |
| `PATCH` / `DELETE` | `admin/products/{product}/attributes/{attribute}` | `product.update` | yes |
| `POST` | `admin/products/{product}/categories`, `…/tags` (`{ ids, mode: sync\|attach\|detach }`) | `product.update` | yes |
| `POST` | `admin/products/{product}/children` (`{ children: [...] }`) | `product.update` | yes |
| `POST` | `admin/products/{product}/stock` (`{ delta, reason, product_variant_id? }`) | `product.update` | yes |
| `GET` / `POST` | `admin/product-categories` | `product.viewAny` / `product.create` | POST |
| `PATCH` / `DELETE` | `admin/product-categories/{category}` | `product.update` / `product.delete` | yes |
| `POST` | `admin/product-categories/reorder` (`{ parent_id, ids }`) | `product.update` | yes |
| `GET` / `POST` | `admin/product-tags` | `product.viewAny` / `product.create` | POST |
| `PATCH` / `DELETE` | `admin/product-tags/{tag}` | `product.update` / `product.delete` | yes |
| `POST` | `admin/product-tags/{tag}/merge` (`{ target_id }`) | `product.update` and `product.delete` | yes |
| `GET` | `admin/inventory` | `product.viewAny` | |
| `GET` | `admin/activity/products/{product}` | `product.view` | |
| `GET` | `admin/activity/customers/{customer}` | `customer.view` | |
| `GET` | `admin/activity/promotions/{promotion}` | `promotion.view` | |
| `GET` / `POST` | `admin/order-substatuses` (`filter[system_status]`) | `orderSubstatus.viewAny` / `.create` | POST |
| `PATCH` / `DELETE` | `admin/order-substatuses/{substatus}` | `orderSubstatus.update` / `.delete` | yes |
| `POST` | `admin/order-substatuses/reorder` (`{ system_status, ids }`) | `orderSubstatus.update` | yes |
| `GET` / `POST` | `admin/tax-classes` | `taxRate.viewAny` / `taxRate.create` | POST |
| `GET` / `POST` | `admin/tax-rates` | `taxRate.viewAny` / `taxRate.create` | POST |
| `PATCH` / `DELETE` | `admin/tax-rates/{rate}` | `taxRate.update` / `taxRate.delete` | yes |
| `GET` / `POST` | `admin/shipping-zones` | `shippingZone.viewAny` / `.create` | POST |
| `PATCH` / `DELETE` | `admin/shipping-zones/{zone}` | `shippingZone.update` / `.delete` | yes |
| `POST` | `admin/shipping-zones/{zone}/methods` | `shippingZone.update` | yes |
| `PATCH` / `DELETE` | `admin/shipping-methods/{method}` | `shippingZone.update` | yes |
| `GET` / `POST` | `admin/promotions` | `promotion.viewAny` / `.create` | POST |
| `PATCH` / `DELETE` | `admin/promotions/{promotion}` | `promotion.update` / `.delete` | yes |
| `POST` | `admin/promotions/{promotion}/coupons` | `coupon.create` | yes |
| `PATCH` / `DELETE` | `admin/coupons/{coupon}` | `coupon.update` / `.delete` | yes |
| `GET` | `admin/reviews`, `admin/reviews/{review}` | `review.viewAny` / `review.view` | |
| `POST` | `admin/reviews/{review}/moderate` | `review.moderate` | yes |
| `DELETE` | `admin/reviews/{review}` | `review.delete` | yes |
| `GET` / `POST` | `admin/digital-files` | `digitalFile.viewAny` / `.create` | POST |
| `PATCH` / `DELETE` | `admin/digital-files/{file}` | `digitalFile.update` / `.delete` | yes |
| `GET` | `admin/license-keys` | `licenseKey.view` | |
| `POST` | `admin/license-keys/{key}/revoke` | `licenseKey.revoke` | yes |
| `GET` | `admin/notification-templates`, `…/{template}` | `notificationTemplate.viewAny` / `.view` | |
| `PATCH` | `admin/notification-templates/{template}` | `notificationTemplate.update` | yes |
| `POST` | `admin/notification-templates/{template}/preview` | `notificationTemplate.update` | |
| `GET` / `POST` | `admin/webhook-subscriptions` | `webhookSubscription.viewAny` / `.create` | POST |
| `PATCH` / `DELETE` | `admin/webhook-subscriptions/{subscription}` | `webhookSubscription.update` / `.delete` | yes |
| `POST` | `admin/webhook-subscriptions/{subscription}/replay/{delivery}` | `webhookSubscription.update` | yes |

Catalog writes refused by `ProductService` (a taken slug or SKU, an unknown
type, a bundle that would loop, …) come back as a 422 `product-write-failed`
problem whose `errors` name the field and a code; see [products.md](products.md).
Address writes refused by `CustomerAddressService` come back the same way as a
422 `customer-write-failed`; see [customers.md](customers.md).
Sub-status writes refused by `OrderSubstatusService` (a taken key, a bad
colour, a sub-status still in use, …) come back as a 422
`substatus-write-failed`; see [kanban.md](kanban.md#order-sub-statuses).

Topic guides: [products.md](products.md), [customers.md](customers.md), [kanban.md](kanban.md), [reviews.md](reviews.md),
[digital-delivery.md](digital-delivery.md), [notifications.md](notifications.md),
[webhooks.md](webhooks.md), [search.md](search.md),
[activity-log.md](activity-log.md).

> **Not in 1.0.0:** the parent plan's checkout endpoints (engine spec §9.2,
> e.g. `POST /checkout/finalize`) and the admin inventory writes (§9.6). Stock
> changes go through `ProductService::adjustStock()` or
> `POST admin/products/{product}/stock`. See the
> [README quick-start](../README.md#quick-start-from-composer-require-to-a-first-order).

### Inbound payment webhooks

`POST /ecommerce/webhooks/{provider}` (outside the API prefix; `api` +
`ecommerce.request-id` middleware; CSRF-exempt). The request is passed to
`handleWebhook()` on the `PaymentGateway` registered under `{provider}`, which
verifies the signature. Verified events are de-duplicated on the provider's
event id, recorded in the inbound delivery ledger, and then fire
`ap.ecommerce.webhook_received` and
`ap.ecommerce.gateway.{provider}.webhook_received`. For Stripe, point the
dashboard at `https://your-store.test/ecommerce/webhooks/stripe`.

## GraphQL

`/graphql/ecommerce` covers the same resources through the same services, so
validation, abilities, and rate policies match REST. Highlights (full details in
[graphql.md](graphql.md)):

- Auth uses the same stack: service signature, Sanctum token or cookie, and
  optional auth for public queries.
- Guards: `graphql.max_depth` (10), `graphql.max_complexity` (5,000, with list
  relations multiplied by `list_complexity_factor` 5), and `graphql.max_batch`
  (10 operations per batched request).
- `Idempotency-Key` is optional on GraphQL. When it's present, the whole HTTP
  request is replayed.
- Automatic persisted queries are handled by rebing's
  `AutomaticPersistedQueriesMiddleware` in the schema's execution stack. Turn
  them on through rebing's own `graphql.apq` config.
- Subscriptions are broadcast on `private-ecommerce.admin` when
  `graphql.subscriptions` is on.
- Extend the schema with the `ap.ecommerce.graphql.extend` filter.

## OpenAPI

`php artisan ecommerce:generate-openapi` writes an OpenAPI 3.1 document
generated from the registered routes, FormRequests, and resource schemas. Its
`x-ecommerce-ability`, `x-rate-limit-policy`, and `x-idempotency` extensions
mirror the tables above. See [openapi.md](openapi.md).
