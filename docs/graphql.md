# GraphQL

The engine serves a GraphQL schema at **`/graphql/ecommerce`** through
rebing/graphql-laravel (engine spec §10). It covers the same resources as the
REST API, so a headless storefront can fetch a product page in one request:

```graphql
query ProductPage($slug: String!) {
  productBySlug(slug: $slug) {
    id name description
    prices { price { amount currency } compare_at { amount } }
    variants { id sku name prices { price { amount } } }
    attributes { key label values { value label swatch } }
  }
}
```

Relations are eager-loaded from the selection, so that query costs the same
handful of SQL queries whether the product has 1 variant or 100.

rebing/graphql-laravel is a suggested dependency, not a required one. Install
it to get the endpoint (`^9.0` on Laravel 12, `^10.0` on Laravel 13):

```bash
composer require rebing/graphql-laravel
```

Without it the engine boots and serves REST as usual, and no GraphQL route is
registered. Turn the endpoint off with
`artisanpack.ecommerce.features.graphql = false`. Its schema has its own type
registry, so its type names (`Product`, `Order`, …) never collide with a host
app's GraphQL schemas.

## Types

- **One object type per REST resource** (`Product`, `ProductVariant`, `Order`,
  `Cart`, `WebhookSubscription`, …). The fields are the REST resource's
  snake_case fields plus its includable relations. Both come from the same
  definition, and GraphQL objects render through the REST resource, so the
  `ap.ecommerce.api.resource.{name}` filters apply to both. Admin-only fields
  (e.g. `Order.ip_address`, `Product.meta`) and admin-only relations
  (`Order.notes`, `timeline`, `edits`, `promotion_usages`; `Cart.customer`;
  `InventoryItem.reservations`; `WebhookSubscription.deliveries`) are `null`
  unless the field was resolved through an admin ability.
- **Connections**: `{Type}Connection { edges { cursor node } nodes pageInfo }`
  with Relay `PageInfo`. List fields take `first` and `after`.
- `Money { amount: BigInt!, currency: String }` (minor units). Scalars:
  `DateTime`, `JSON`, `BigInt` (64-bit). `DateTime` is returned as RFC 3339 in
  UTC with a `Z` suffix (`2026-10-05T14:30:00Z`) and accepts any ISO 8601
  string as input.
- `UserError { field, code, message }` for expected mutation failures.

## Queries

| Field | Access | Rate policy |
|---|---|---|
| `product(id)`, `productBySlug(slug)`, `products(filter, sort, first, after)`, `search(query, first, after)` | public (storefront-visible products only) | `ecommerce.catalog.read` |
| `cart(token)`, `cartShippingRates(token, country_code, region_code, postal_code)`, `checkout(token)` | anyone holding the token; a cart that belongs to a customer account also needs that account's session or token | `ecommerce.cart.mutate` |
| `guestOrderLookup(email, order_number)`, `orderByViewToken(token)` | public | `ecommerce.lookup.attempt` |
| `me`, `myOrders` | signed-in shopper, token with `ecommerce:storefront` (or admin) | `ecommerce.admin.mutate` |
| `order(id)` | `ecommerce.order.view`, or the shopper who owns it | `ecommerce.admin.mutate` |
| `orders(filter)`, `refund`, `customer`, `customers(filter)`, `promotion`, `promotions`, `taxClasses`, `taxRates`, `shippingZones`, `shippingZone`, `inventoryItems`, `orderSubstatuses(systemStatus)`, `webhookSubscriptions`, `webhookSubscription`, `notificationTemplates`, `notificationTemplate` | the matching `ecommerce.{resource}.{action}` ability | `ecommerce.admin.mutate` |

Notes on the storefront fields:

- `products` takes a `ProductFilter` (`type`, `sku`, `slug`, `search`,
  `category`, `descendants`, `tag`, `price_min`, `price_max`, `currency`,
  `attributes`, `in_stock`, `on_sale`, `featured`, `min_rating`, `ids`) and a
  `sort` of `position` (the default), `name`, `-name`, `newest`, or `rating`.
  The price, popularity, and relevance sorts are REST-only, because they page
  by number rather than by cursor.
- `search` takes a `query` of 1–200 characters.
- `cart`, `cartShippingRates`, and `checkout` return `null` for an unknown
  token or a cart the caller can't open. `checkout` returns a `CheckoutInfo`:
  `state`, `requires_shipping`, `shipping_rates`, `gateways`,
  `guest_checkout`, and `account_creation`.
- `guestOrderLookup` returns `null` when the email or order number doesn't
  match, and a `RATE_LIMITED` error once repeated failures lock the order
  number out. `orderByViewToken` returns `null` for a bad or expired link.

## Mutations

Each mutation mirrors a REST endpoint and calls the same service with the same
validation, auth, and rate-limit policy. Inputs use the REST payload's
snake_case keys. Each mutation takes one `input` argument and returns
`{ …, errors: [UserError!]!, clientMutationId }`. Cart and checkout inputs
name the cart with `cart_token`.

| Mutation | REST |
|---|---|
| `createCart` | `POST carts` |
| `updateCart` | `PATCH carts/{cart}` |
| `addToCart` | `POST carts/{cart}/items` (price resolved server-side) |
| `updateCartItem` / `removeCartItem` | `PATCH` / `DELETE carts/{cart}/items/{item}` |
| `clearCart` | `DELETE carts/{cart}/items` |
| `applyCoupon` / `removeCoupon` | `POST carts/{cart}/coupons` / `DELETE carts/{cart}/coupons/{code}` |
| `selectCartShippingRate` | `PUT carts/{cart}/shipping-rate` |
| `mergeCart` (signed in; returns `cart`, `merged`, `pending`) | `POST carts/{cart}/merge` |
| `startCheckout` (returns `cart`, `adjustments`) | `POST checkout/{cart}/start` |
| `setCheckoutAddress` (returns `cart`, `shipping_rates`) | `POST checkout/{cart}/address` |
| `setShippingMethod` | `POST checkout/{cart}/shipping-method` |
| `setPaymentGateway` | `POST checkout/{cart}/payment-gateway` |
| `createPaymentSession` (returns `cart`, `session { reference status amount client }`) | `POST checkout/{cart}/session` |
| `placeOrder` (returns `order`, `status`, `complete`, `step_up_token`) | `POST checkout/{cart}/finalize` |
| `issueRefund` | `POST orders/{order}/refunds` |
| `cancelOrder` | `POST orders/{order}/cancel` |
| `addOrderNote` | `POST orders/{order}/notes` |
| `adjustInventory` (`{ inventory_item_id, delta, reason }`) | `POST admin/inventory/{item}/adjust` |
| `createWebhookSubscription` / `updateWebhookSubscription` / `deleteWebhookSubscription` / `replayWebhookDelivery` | `admin/webhook-subscriptions…` |
| `updateNotificationTemplate` | `PATCH admin/notification-templates/{template}` |
| `previewNotificationTemplate` (returns `rendered { subject body }`) | `POST admin/notification-templates/{template}/preview` |
| `createProduct` / `updateProduct` / `deleteProduct` | `POST admin/products` / `PATCH` / `DELETE admin/products/{product}` |
| `createProductVariant` / `updateProductVariant` / `deleteProductVariant` | `admin/products/{product}/variants…` |
| `createProductPrice` / `updateProductPrice` / `deleteProductPrice` | `admin/products/{product}/prices…` |
| `createCategory` / `updateCategory` / `deleteCategory` | `admin/product-categories…` |
| `createTag` / `updateTag` / `deleteTag` | `admin/product-tags…` |
| `createOrderSubstatus` / `updateOrderSubstatus` / `deleteOrderSubstatus` | `admin/order-substatuses…` |
| `reorderOrderSubstatuses` (returns `substatuses`) | `POST admin/order-substatuses/reorder` |

`createPaymentSession` and `placeOrder` use the `ecommerce.checkout.finalize`
rate policy, `applyCoupon` uses `ecommerce.coupon.attempt`, and the other cart
and checkout mutations use `ecommerce.cart.mutate`. When `mergeCart` finds
carts in different currencies, it returns `merged: false`, a `pending` block
(`guest_currency`, `account_currency`, `resolutions`), and a
`currency-mismatch` error. Send it again with `resolution` set to
`keep_guest_currency` or `switch_to_account_currency`.

The catalog mutations take the same keys as the REST bodies. `prices`,
`images`, and `children` are typed input lists; `attributes`, `inventory`,
`option_values`, `stock_adjustment`, and `meta` are `JSON`. Catalog rules
refused by `ProductService` come back as `UserError`s with the codes in
[products.md](products.md#errors).

```graphql
mutation Add($input: AddToCartInput!) {
  addToCart(input: $input) {
    cart { subtotal { amount currency } items { quantity product { name } } }
    errors { field code message }
  }
}
# variables: { "input": { "cart_token": "…", "product_id": 12, "quantity": 2 } }
```

Expected failures (validation, an unavailable product, an invalid coupon, a
checkout step out of order, a refused refund, a notification template the
sandbox rejects, a missing or reused `Idempotency-Key`) come back in `errors`.
Validation codes are the failed rule's name in kebab case, as on REST. Auth,
not-found, and rate-limit failures are top-level GraphQL errors with
`extensions.code` set to `UNAUTHENTICATED`, `FORBIDDEN`, `NOT_FOUND`,
`RATE_LIMITED` (with `retry_after`), or `BAD_USER_INPUT`.

These features are REST-only in 1.0.0: reviews, product views, downloads,
licenses, guest-order claims, the shopper's addresses and notification
preferences, kanban, settings, reports, the activity log, admin inventory
settings, product images, attributes, relations, and stock, and webhook
delivery history. Their services exist, so satellites can add fields for them
with [`ap.ecommerce.graphql.extend`](#extending-the-schema).

## Authentication

The endpoint serves public and authenticated fields in the same request, so it
resolves credentials without requiring them: Sanctum bearer tokens, Sanctum
cookie sessions, and signed service requests all work (see
[api-auth.md](api-auth.md)). Each field then enforces its own ability and token
scope. Like REST, the endpoint negotiates `Accept-Language` and sets
`Content-Language` (see [localization.md](localization.md#negotiating-the-language)).

An `Idempotency-Key` header is optional for most requests. When it's sent,
the whole HTTP request is replayed on a retry with the same key and body.
Three mutations move money or stock and **require** it: `issueRefund`,
`cancelOrder`, and `adjustInventory`. Without the header they return an
`idempotency-key-required` error and do nothing. A retry with the same key and
input returns the first result, and the same key with different input returns
an `idempotency-conflict` error. These keys are scoped to the acting user.

Before anything executes, each operation is checked:

- **GET runs queries only.** Mutations must be sent with POST, so a
  cross-site `GET` can't perform one with an admin's cookie session.
- **Depth:** at most `graphql.max_depth` levels (default 10, env
  `ECOMMERCE_GRAPHQL_MAX_DEPTH`).
- **Complexity:** at most `graphql.max_complexity` cost (default 5,000, env
  `ECOMMERCE_GRAPHQL_MAX_COMPLEXITY`). Each field costs 1, a connection
  multiplies its selection by `first`, and a relation list multiplies by
  `graphql.list_complexity_factor` (default 5), so nested lists and aliases
  are priced by what they would load. A 25-product listing with prices and
  variants costs about 1,400.
- **Batching:** at most `graphql.max_batch` operations per request (default 10),
  whether sent as JSON, form fields, or a multipart `operations` field.
- **Introspection:** `__schema` and `__type` queries follow
  `graphql.introspection` (env `ECOMMERCE_GRAPHQL_INTROSPECTION`). Unset, they
  are allowed everywhere except production.

These limits apply to the ecommerce schema only. rebing's own
`graphql.security` settings don't apply to it, and the engine never changes
them for a host app's other schemas.

List fields cap `first` at `api.max_per_page`, and `search` pages at most 10,000
results deep. Its cursors must be reused with the same `first`.

## Subscriptions

The `Subscription` type describes the real-time payloads:

| Field | Payload | Channel |
|---|---|---|
| `orderStatusChanged` | `{ order, from, to }` | `private-ecommerce.admin` |
| `paymentSucceeded` | `{ order, payment }` | `private-ecommerce.admin` |
| `stockChanged` | `{ inventory_item, delta, new_level }` | `private-ecommerce.admin` |
| `webhookDeliveryFailed` | `{ delivery, reason }` | `private-ecommerce.admin` |
| `reviewSubmitted` | `{ review }` | `private-ecommerce.admin` |
| `kanbanCardMoved` | `{ card, from_column_id, to_column_id, board_id }` | `private-ecommerce.kanban.board.{boardId}` |

They're delivered over Laravel broadcasting (Reverb, Pusher, …), not over HTTP.
Set `ECOMMERCE_GRAPHQL_SUBSCRIPTIONS=true` with a configured broadcaster. Each
event is broadcast as `{field}` with a body shaped like a GraphQL response:

```js
Echo.private('ecommerce.admin').listen('.orderStatusChanged', ({ data }) => {
  console.log(data.orderStatusChanged.order.order_number, data.orderStatusChanged.to);
});
```

The `ecommerce.admin` channel is authorized by the `ecommerce.order.viewAny`
ability. `kanbanCardMoved` is broadcast only when
`ECOMMERCE_KANBAN_BROADCAST=true`, and its channel needs the
`kanbanBoard.view` ability (see [kanban.md](kanban.md)).

## Extending the schema

Satellites add types and fields through `ap.ecommerce.graphql.extend`. It
receives the whole definition array (`types`, `query`, `mutation`,
`subscription`) and the type registry. Fields are an SDL type string or an
array with `type`, `args`, `resolve`, and `description`:

```php
addFilter( 'ap.ecommerce.graphql.extend', function ( array $schema ): array {
    $schema['types']['Product']['fields']['brand'] = [
        'type'    => 'String',
        'resolve' => fn ( array $product ): ?string => Brand::forProduct( $product['id'] )?->name,
    ];

    $schema['types']['RecurringPlan'] = [
        'description' => 'A recurring billing plan.',
        'fields'      => [ 'id' => 'ID!', 'status' => 'String!', 'next_billing_at' => 'DateTime' ],
    ];

    $schema['query']['recurringPlan'] = [
        'type'    => 'RecurringPlan',
        'args'    => [ 'id' => 'ID!' ],
        'resolve' => fn ( $root, array $args ): ?array => RecurringPlan::find( $args['id'] )?->toArray(),
    ];

    return $schema;
} );
```

A type name the engine doesn't define falls back to rebing's own type registry,
so a type registered there can be referenced too. To broadcast a subscription
field of your own, add it under `subscription` and dispatch
`new GraphQLSubscriptionBroadcast( $field, $channel, $payload )`.
