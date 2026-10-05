# HTTP API

The engine exposes a versioned REST API, a GraphQL endpoint, one inbound
webhook receiver, and signed unsubscribe links (parent plan §12–13, engine
spec §9–11). This page covers the REST surface, auth, error format,
idempotency, caching, rate limits, and pagination, and summarizes GraphQL and
OpenAPI.

| Surface | Base path | Toggle |
|---|---|---|
| REST | `/{api_prefix}/{api.version}`, which defaults to **`/api/ecommerce/v1`** | `artisanpack.ecommerce.features.rest` |
| GraphQL | `/graphql/ecommerce` (rebing's `graphql.route.prefix` + `/ecommerce`) | `artisanpack.ecommerce.features.graphql` (also needs `rebing/graphql-laravel`) |
| Inbound payment webhooks | `POST /ecommerce/webhooks/{provider}` | always on |
| Notification unsubscribe links | `GET` / `POST /ecommerce/notifications/unsubscribe` (signed) | always on |

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
  URL token is the credential), license validation and deactivation, guest
  order lookup, and signed order links.
- **Cart and checkout routes** use the 40-character cart **token** in the URL
  as the credential. Treat it like a session secret. They also resolve a
  signed-in shopper when one is present (optional auth). A cart that belongs
  to a customer account only opens for that account's session or token;
  anyone else gets a 404, even with the right token.
- **Review submission, review eligibility, and product views** use optional
  auth too: guests are allowed, and a signed-in shopper is recognized.
- **Admin, order, customer, kanban, and `me/*` routes** run the
  `artisanpack.ecommerce.api.auth_middleware` stack, which defaults to
  `ecommerce.service-signature` then `auth:sanctum`.
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
  `ecommerce.admin` Gate decides, otherwise the request is refused. The Gate
  gets the route's model (for example the `Order` on `orders/{order}`) as its
  subject. The `ap.ecommerce.abilities.{resource}.{action}` filter can then
  override that decision, but a Sanctum token's own abilities are checked
  afterwards: the filter can't grant an action the caller's token doesn't carry.
- `me/*` routes act on the signed-in user's own customer record. A Sanctum
  token needs `ecommerce:storefront` or `ecommerce:admin` (403 otherwise), and
  an account with no customer record gets a 404 `customer-not-found`.

## Language and timestamps

The `ecommerce.locale` middleware runs on every REST and GraphQL request. It
matches `Accept-Language` against `artisanpack.ecommerce.localization.supported_locales`
(default `en`, `es`, `fr`, `de`) and uses the best match for messages,
problem details, and formatted money. Without a usable header, the
application locale is kept. Every response carries `Content-Language` and
`Vary: Accept-Language`. See [localization.md](localization.md#negotiating-the-language).

Every timestamp in a response is RFC 3339 in UTC with a `Z` suffix, for
example `2026-10-05T14:30:00Z`.

## Error format

Every error the REST API produces is `application/problem+json` (RFC 9457):

```json
{
  "type": "https://docs.artisanpack-ui.dev/ecommerce/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "The request payload failed validation.",
  "instance": "/api/ecommerce/v1/carts/abc…/items",
  "errors": [
    { "field": "quantity", "code": "min", "message": "The quantity field must be at least 1." }
  ]
}
```

`type` is `{problem_base_url}/{slug}`. Set the base with
`artisanpack.ecommerce.idempotency.problem_base_url` (rate-limit problems use
`artisanpack.ecommerce.rate_limits.problem_base_url`). `errors` only appears
when there are field-level errors. A validation error's `code` is the failed
rule's name in kebab case (`required`, `min`, `max`, `email`, `unique`, `in`,
…). A closure or custom rule object reports `invalid`.

| Status | Slug | When |
|---|---|---|
| 400 | `missing-idempotency-key` | An endpoint that requires `Idempotency-Key` was called without one |
| 400 | `oversized-idempotency-key` | The key is longer than 255 characters |
| 400 | `weak-idempotency-key` | A caller with no session or token sent a key that isn't 16–255 letters, digits, or hyphens |
| 400 | `invalid-list-query` | Unknown `filter[…]`, `sort`, or `include`, or a badly typed filter value |
| 400 | `invalid-parameter` | A bad query parameter, such as an unknown `type` on `products/{product}/related` |
| 401 | `unauthenticated` | Missing or invalid credentials on an authenticated route |
| 401 | `invalid-service-signature` | A signed service request failed verification |
| 403 | `forbidden` | The caller lacks the route's ability (`detail` names it) |
| 404 | `not-found` | Unknown id, slug, or cart token, or a cart the caller can't open |
| 405 | `method-not-allowed` | The path exists but not for this method |
| 409 | `idempotency-key-conflict` | The key was reused with a different payload, or the original request is still in flight |
| 409 | `cart-currency-mismatch` | `POST carts/{cart}/merge` found carts in different currencies; resend with a `resolution` |
| 422 | `validation-failed` | FormRequest validation failed |
| 422 | a cart or checkout error code, e.g. `product-unavailable`, `quantity-limit`, `coupon-invalid`, `address-required`, `gateway-unavailable` | An expected cart or checkout failure (`CartOperationException`) |
| 422 | `product-write-failed`, `customer-write-failed`, `substatus-write-failed`, `settings-write-failed` | A service refused an admin write (see [REST routes](#rest-routes)) |
| 429 | `rate-limited` | A rate-limit policy was exceeded (see [Rate limits](#rate-limits)) |
| 500 | `server-error` | An unexpected error. `detail` is generic unless `app.debug` is on. |

Topic pages list their own slugs, for example `order-not-found` and
`lookup-locked` in [customers.md](customers.md#guest-orders), or
`refund-not-allowed` and `order-not-cancellable` on the order routes. Any other
HTTP error on an API route comes back as `http-{status}`.

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

Two cases page by number (`page` / `per_page`) instead of by cursor:

- `GET products` and `GET categories/{category}/products` with one of the
  computed sorts `price`, `-price`, `popularity`, `relevance`, or `newest`.
  These lists also take `q`, `currency`, `attributes[key]=a,b`, and
  `facets=1` (adds `meta.facets`).
- `GET search?q=…`. It goes through the configured search provider and returns
  `meta.facets` and `meta.suggestions`. See [search.md](search.md).

Each endpoint's filters, sorts, includes, and query parameters are listed in
the generated [OpenAPI spec](openapi.md).

## Caching

Routes carry an `ecommerce.cache` mode:

- **Public catalog reads** (`products…`, `categories…`, `tags`, `search`) get a
  weak `ETag` on a 200, `Cache-Control: public, max-age=60` (`private` when the
  caller is signed in), and `Vary: Accept, Accept-Language, Authorization`.
  Set the age with `artisanpack.ecommerce.api.catalog_max_age` (env
  `ECOMMERCE_API_CATALOG_MAX_AGE`). A request whose `If-None-Match` matches
  the `ETag` gets `304 Not Modified` with no body.
- **Cart, checkout, review-eligibility, guest-lookup, and order-link routes**
  get `Cache-Control: private, no-store`.

## Idempotency

Every mutating REST endpoint requires an **`Idempotency-Key`** header, and a
retry with the same key replays the original response instead of repeating the
side effect (engine spec §11.2). This covers every cart and checkout write
(including `POST /carts`), review submission, product views, license
validation and deactivation, every `me/*` write, and every order, customer,
kanban, and admin write. The one exception is the read-only
`POST admin/notification-templates/{template}/preview`. On GraphQL the header
is optional, except on the money- and stock-moving mutations (see
[graphql.md](graphql.md#authentication)).

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
  signed service, the signed-in user, the session, or (for anonymous API calls)
  the client IP. The endpoint is the route name.
- **Guest keys.** Anonymous callers behind one NAT share an IP actor scope, so
  without a session or token the key must be 16–255 letters, digits, or
  hyphens (a UUID works). A shorter or odd key gets **400
  `weak-idempotency-key`**. Always generate keys randomly.
- **Request fingerprint.** The fingerprint is a SHA-256 of the canonicalized
  body (key order doesn't matter), the query string, uploaded files (by
  content), and the route parameters. Reusing a key with a different
  fingerprint returns **409 `idempotency-key-conflict`**. That includes the
  same key against a different cart token.
- **Replay.** The first response is stored, whatever its status (including
  4xx). A matching retry gets back the same status, body, and headers, minus
  `Set-Cookie`, plus `Idempotent-Replay: true`. Responses that contain
  secrets are stored with those fields redacted: a new webhook subscription's
  `secret`, and the cart `token` of a guest's `POST carts`.
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
`artisanpack.ecommerce.rate_limits.{policy}.{bucket}` or with the env var
shown. A blank, zero, or negative value falls back to the default.

| Policy | Buckets (default) | Bucket key | Used by |
|---|---|---|---|
| `ecommerce.catalog.read` | 300/min per IP (`ECOMMERCE_RATE_CATALOG_READ_PER_IP`) | client IP | catalog reads, search, review eligibility, product views, downloads |
| `ecommerce.cart.mutate` | 60/min per cart (`…_CART_MUTATE_PER_CART`) + 300/min per IP (`…_CART_MUTATE_PER_IP`) | cart token from the route, `cart_token` input, or `X-Cart-Token` header (falls back to IP); IP | cart reads and writes, checkout steps |
| `ecommerce.coupon.attempt` | 10/hour per cart (`…_COUPON_ATTEMPT_PER_CART`) + 30/hour per IP (`…_COUPON_ATTEMPT_PER_IP`) | as above | `POST carts/{cart}/coupons` |
| `ecommerce.checkout.finalize` | 6/min per IP (`…_CHECKOUT_FINALIZE_PER_IP`) + 12/hour per cart (`…_CHECKOUT_FINALIZE_PER_CART`) | IP; cart token | `POST checkout/{cart}/session`, `POST checkout/{cart}/finalize` |
| `ecommerce.review.submit` | 3/hour per customer (guests: per IP) (`…_REVIEW_SUBMIT_PER_CUSTOMER`) + 10/hour per IP (`…_REVIEW_SUBMIT_PER_IP`) | user id; IP | `POST products/{product}/reviews` |
| `ecommerce.claim.attempt` | 5 per 60 minutes per shopper (`customers.claim_rate_limit` / `claim_rate_window_minutes`) + 30/hour per IP | user id; IP | `POST me/claims` |
| `ecommerce.lookup.attempt` | 30/min per IP (`…_LOOKUP_ATTEMPT_PER_IP`) | IP | `GET orders/guest-lookup`, `GET order-views/{token}` |
| `ecommerce.license.validate` | 60/min per license key (only when a key is sent) (`…_LICENSE_VALIDATE_PER_LICENSE`) + 600/min per IP (`…_LICENSE_VALIDATE_PER_IP`) | `key` / `license_key` input; IP | `POST license/validate`, `POST license/deactivate` |
| `ecommerce.webhook.inbound` | 120/min per IP (`…_WEBHOOK_INBOUND_PER_IP`) | IP | every request to `POST /ecommerce/webhooks/{provider}` |
| `ecommerce.webhook.verified` | 1,000/min per provider (`…_WEBHOOK_INBOUND_PER_PROVIDER`) | `{provider}` route segment | counted by the webhook controller after the signature is verified |
| `ecommerce.notifications.unsubscribe` | 30/min per IP (`ECOMMERCE_RATE_UNSUBSCRIBE_PER_IP`) | IP | the unsubscribe links |
| `ecommerce.admin.mutate` | 120/min per user (anonymous: per IP) (`…_ADMIN_MUTATE_PER_USER`) | user id; IP | every admin, order, customer, kanban, and `me/*` route, reads included |
| `ecommerce.login` | 5/min per IP (`…_LOGIN_PER_IP`) + 20/hour per email (only when an email is sent) (`…_LOGIN_PER_EMAIL`) | IP; `email` input | registered for storefront and admin login surfaces; no engine route uses it |

Every `…` env var starts with `ECOMMERCE_RATE`.

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
`ecommerce.order.refund`. Policy names drop the `ecommerce.` prefix.

### Catalog (public)

All of these use the `catalog.read` policy and the public cache mode.

| Method | Path | Notes |
|---|---|---|
| `GET` | `products` | Storefront-visible products. Filters, sorts, and includes in the [OpenAPI spec](openapi.md). |
| `GET` | `products/{product}` | By id |
| `GET` | `products/{product}/variants` | |
| `GET` | `products/{product}/related` | `?type=related\|upsell\|cross_sell&limit=` (1–50) |
| `GET` | `products/{product}/purchase-options` | `?currency&country_code&region_code&postal_code` → `{ product_id, currency, price, stock, variants, options }` |
| `GET` | `products/{product}/reviews` | Approved reviews, with `meta.histogram` and `meta.average` |
| `GET` | `search?q=…` | See [search.md](search.md) |
| `GET` | `categories` | The whole tree, nested under `children` |
| `GET` | `categories/{category}` | By id or slug; `include=parent,children` |
| `GET` | `categories/{category}/products` | `GET products` limited to the category and its sub-categories |
| `GET` | `tags` | Every tag with `products_count` |

### Reviews, product views, downloads, and licenses

| Method | Path | Auth | Rate policy | Idem. |
|---|---|---|---|---|
| `POST` | `products/{product}/reviews` | optional | `review.submit` | yes |
| `GET` | `products/{product}/reviews/eligibility` → `{ allowed, reason, verified_purchase }` | optional | `catalog.read` | |
| `POST` | `products/{product}/views` → 202 (fires `ap.ecommerce.product.viewed` for satellites such as recently-viewed) | optional | `catalog.read` | yes |
| `GET` | `downloads/{token}` | the token | `catalog.read` | |
| `GET` | `downloads/{token}/stream` (supports `Range`) | the token | `catalog.read` | |
| `POST` | `license/validate` (`{ key, fingerprint }`) | public | `license.validate` | yes |
| `POST` | `license/deactivate` (`{ key, fingerprint }`) → `{ deactivated, activations_count, activations_limit, reason }` | public | `license.validate` | yes |

A submission the shopper isn't eligible for gets a 403 whose slug is the
reason (`already-reviewed`, `purchase-required`), or a 401 for a guest when
guests can't review. See [reviews.md](reviews.md) and
[digital-delivery.md](digital-delivery.md).

### Cart (the token is the credential)

All cart routes use optional auth and the `cart.mutate` policy unless noted.

| Method | Path | Body or query | Idem. |
|---|---|---|---|
| `POST` | `carts` | `{ currency?: "USD", email? }` | yes |
| `GET` | `carts/{cart}` | | |
| `PATCH` | `carts/{cart}` | `{ email?, shipping_address?, billing_address? }` | yes |
| `POST` | `carts/{cart}/items` | `{ product_id, quantity (1–10000), product_variant_id?, options? }` | yes |
| `DELETE` | `carts/{cart}/items` (empties the cart) | | yes |
| `PATCH` | `carts/{cart}/items/{item}` | `{ quantity }` (0 removes the line) | yes |
| `DELETE` | `carts/{cart}/items/{item}` | | yes |
| `POST` | `carts/{cart}/coupons` (`coupon.attempt` policy) | `{ code }` | yes |
| `DELETE` | `carts/{cart}/coupons/{code}` | | yes |
| `GET` | `carts/{cart}/cross-sells` | | |
| `GET` | `carts/{cart}/shipping-rates` | `?country_code&region_code&postal_code&city` | |
| `PUT` | `carts/{cart}/shipping-rate` | `{ rate_id, destination: { country_code, region_code?, postal_code?, city? } }` | yes |
| `POST` | `carts/{cart}/merge` (signed in; merges a guest cart into the account's cart) | `{ resolution? }` | yes |

Prices are always resolved on the server. The client never sends a unit price.

### Checkout (the token is the credential)

All checkout routes use optional auth. Each response is the cart with a
`checkout` block: `state`, `requires_shipping`, `shipping_rates`, `gateways`,
`guest_checkout`, and `account_creation`.
[Checkout](./checkout.md) walks through the whole flow.

| Method | Path | Body | Rate policy | Idem. |
|---|---|---|---|---|
| `GET` | `checkout/{cart}` | | `cart.mutate` | |
| `POST` | `checkout/{cart}/start` (holds stock; adds `checkout.adjustments`) | | `cart.mutate` | yes |
| `POST` | `checkout/{cart}/address` | `{ email?, shipping_address?, billing_address? }` | `cart.mutate` | yes |
| `POST` | `checkout/{cart}/shipping-method` | `{ rate_id }` | `cart.mutate` | yes |
| `POST` | `checkout/{cart}/payment-gateway` | `{ gateway }` | `cart.mutate` | yes |
| `POST` | `checkout/{cart}/session` (adds `checkout.payment`) | `{ return_url? }` (must be on this site) | `checkout.finalize` | yes |
| `POST` | `checkout/{cart}/finalize` | `{ payment_reference?, customer_note? }` | `checkout.finalize` | yes |

`finalize` returns the order with a `checkout` block of `status`, `complete`,
and `step_up_token`. It answers 201 when the payment was captured and 200
otherwise, for example when `step_up_token` asks the shopper to finish 3-D
Secure or a redirect before finalizing again.

### Guest order access (public)

| Method | Path | Rate policy |
|---|---|---|
| `GET` | `orders/guest-lookup?email=…&order_number=…` | `lookup.attempt` |
| `GET` | `order-views/{token}` (a signed order link) | `lookup.attempt` |

A wrong email and an unknown order number both answer 404 `order-not-found`.
Repeated failures lock the order number out with a 429 `lookup-locked` and
`Retry-After`. A bad or expired link is a 404 `order-link-invalid`. See
[customers.md](customers.md#guest-orders).

### The signed-in shopper (`me/…`, authenticated)

All `me/*` routes use the `admin.mutate` policy except `POST me/claims`.

| Method | Path | Idem. |
|---|---|---|
| `GET` / `PATCH` | `me` (`{ first_name?, last_name?, phone?, accepts_marketing?, locale? }`) | PATCH |
| `GET` | `me/account-menu` | |
| `GET` / `POST` | `me/addresses` | POST |
| `PATCH` / `DELETE` | `me/addresses/{address}` | yes |
| `POST` | `me/claims` (`{ order_number, postal_code }`; `claim.attempt` policy) | yes |
| `GET` | `me/orders`, `me/orders/{order}` | |
| `GET` | `me/downloads`, `me/downloads/{download}`, `me/downloads/{download}/stream` | |
| `GET` | `me/license-keys` | |
| `GET` / `PATCH` | `me/notification-preferences` | PATCH |

A claim that matches no order is a 422 `claim-not-verified`. See
[customers.md](customers.md#the-shoppers-own-account).

### Orders and customers (authenticated, `admin.mutate`)

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
| `POST` | `admin/products/{product}/relations` (`{ type: related\|upsell\|cross_sell, ids }`, replaces that type's list) | `product.update` | yes |
| `POST` | `admin/products/{product}/stock` (`{ delta, reason, product_variant_id? }`) | `inventory.adjust` | yes |
| `GET` / `POST` | `admin/product-categories` | `product.viewAny` / `product.create` | POST |
| `PATCH` / `DELETE` | `admin/product-categories/{category}` | `product.update` / `product.delete` | yes |
| `POST` | `admin/product-categories/reorder` (`{ parent_id, ids }`) | `product.update` | yes |
| `GET` / `POST` | `admin/product-tags` | `product.viewAny` / `product.create` | POST |
| `PATCH` / `DELETE` | `admin/product-tags/{tag}` | `product.update` / `product.delete` | yes |
| `POST` | `admin/product-tags/{tag}/merge` (`{ target_id }`) | `product.update` and `product.delete` | yes |
| `GET` | `admin/inventory` | `inventory.viewAny` | |
| `PATCH` | `admin/inventory/{item}` (`{ track_inventory?, allow_backorder?, low_stock_threshold? }`) | `inventory.adjust` | yes |
| `POST` | `admin/inventory/{item}/adjust` (`{ delta, reason }`) | `inventory.adjust` | yes |
| `GET` | `admin/settings` (groups) | `settings.view` | |
| `GET` | `admin/settings/{group}` (values and secret statuses) | `settings.view` | |
| `PATCH` | `admin/settings/{group}` (`{ values, reset?, confirm_base_currency_change? }`) | `settings.update` | yes |
| `GET` | `admin/reports` | `report.view` | |
| `GET` | `admin/reports/{report}` (`from`, `to`, `interval`, `compare`, report options) | `report.view` | |
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
| `GET` | `admin/shipping-method-types` (the registered method types, for a method editor) | `shippingZone.viewAny` | |
| `GET` / `POST` | `admin/promotions` | `promotion.viewAny` / `.create` | POST |
| `PATCH` / `DELETE` | `admin/promotions/{promotion}` | `promotion.update` / `.delete` | yes |
| `GET` | `admin/promotion-conditions`, `admin/promotion-actions` (the registered types, for a promotion editor) | `promotion.viewAny` | |
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
| `GET` | `admin/webhook-subscriptions/{subscription}/deliveries`, `…/deliveries/{delivery}` | `webhookSubscription.viewAny` | |
| `POST` | `admin/webhook-subscriptions/{subscription}/replay/{delivery}` | `webhookSubscription.update` | yes |
| `POST` | `admin/webhook-subscriptions/{subscription}/replay-parked` | `webhookSubscription.update` | yes |

Catalog writes refused by `ProductService` (a taken slug or SKU, an unknown
type, a bundle that would loop, …) come back as a 422 `product-write-failed`
problem whose `errors` name the field and a code; see [products.md](products.md).
Address writes refused by `CustomerAddressService` come back the same way as a
422 `customer-write-failed`; see [customers.md](customers.md).
Sub-status writes refused by `OrderSubstatusService` (a taken key, a bad
colour, a sub-status still in use, …) come back as a 422
`substatus-write-failed`; see [kanban.md](kanban.md#order-sub-statuses).
Settings writes refused by `SettingsRepository` (a key that is not
allow-listed, an invalid value, an unconfirmed base-currency change) come back
as a 422 `settings-write-failed`; see [settings.md](settings.md).

A `stock_adjustment` inside `PATCH admin/products/{product}` or
`…/variants/{variant}` needs `inventory.adjust` as well as `product.update`.

Topic guides: [products.md](products.md), [customers.md](customers.md), [kanban.md](kanban.md), [reviews.md](reviews.md),
[digital-delivery.md](digital-delivery.md), [notifications.md](notifications.md),
[webhooks.md](webhooks.md), [search.md](search.md),
[activity-log.md](activity-log.md), [settings.md](settings.md),
[reports.md](reports.md).

### Inbound payment webhooks

`POST /ecommerce/webhooks/{provider}` (outside the API prefix; `api`,
`ecommerce.request-id`, and the `ecommerce.webhook.inbound` rate limit;
CSRF-exempt). The request is passed to `handleWebhook()` on the
`PaymentGateway` registered under `{provider}`, which verifies the signature.
For Stripe, point the dashboard at
`https://your-store.test/ecommerce/webhooks/stripe`.

| Response | When |
|---|---|
| `200 { received, event_id, type }` | A verified event. A redelivered event id adds `"duplicate": true` and fires nothing. |
| `400 { code, message }` | The signature didn't verify. Only the body's hash, size, and first 1 KB are kept in the ledger. |
| `404 { code: "gateway_not_registered" }` | No gateway is registered under `{provider}`. Nothing is stored. |
| `413 { code: "payload_too_large" }` | The body is over `webhooks.inbound_max_bytes` (512 KB). Nothing is stored. |
| `429 { code: "rate_limited" }` + `Retry-After` | The provider used up its verified allowance (`ecommerce.webhook.verified`) |

Verified events are de-duplicated on the provider's event id, recorded in the
inbound delivery ledger, and then fire `ap.ecommerce.webhook_received` and
`ap.ecommerce.gateway.{provider}.webhook_received`. A payment outcome also
settles its checkout session in a queued job.

### Unsubscribe links

Opt-out notification mail carries a signed link to
`/ecommerce/notifications/unsubscribe` (route names
`ecommerce.notifications.unsubscribe` and `.store`; `signed` middleware,
`ecommerce.notifications.unsubscribe` rate limit). `GET` shows a confirmation
page and changes nothing, so a link scanner can't unsubscribe anyone. `POST`
to the same URL turns the category off. That's also what mail clients send for
RFC 8058 one-click unsubscribe. See
[notifications.md](notifications.md#customer-preferences).

## GraphQL

`/graphql/ecommerce` covers the same resources through the same services, so
validation, abilities, and rate policies match REST. Highlights (full details in
[graphql.md](graphql.md)):

- It needs `rebing/graphql-laravel`, which is suggested rather than required.
  Without it the engine boots and serves REST only.
- Auth uses the same stack: service signature, Sanctum token or cookie, and
  optional auth for public queries.
- Guards: `graphql.max_depth` (10), `graphql.max_complexity` (5,000, with list
  relations multiplied by `list_complexity_factor` 5), `graphql.max_batch`
  (10 operations per batched request), and `graphql.introspection` (off in
  production by default).
- `Idempotency-Key` is optional on GraphQL. When it's present, the whole HTTP
  request is replayed. `issueRefund`, `cancelOrder`, and `adjustInventory`
  refuse to run without one.
- Automatic persisted queries are handled by rebing's
  `AutomaticPersistedQueriesMiddleware` in the schema's execution stack. Turn
  them on through rebing's own `graphql.apq` config.
- Subscriptions are broadcast on `private-ecommerce.admin` when
  `graphql.subscriptions` is on.
- Extend the schema with the `ap.ecommerce.graphql.extend` filter.

## OpenAPI

`php artisan ecommerce:generate-openapi` writes an OpenAPI 3.1 document
generated from the registered routes, FormRequests, and resource schemas. Its
`x-ecommerce-ability`, `x-token-scopes`, `x-rate-limit-policy`, and
`x-idempotency` extensions mirror the tables above. See [openapi.md](openapi.md).
