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

Turn the endpoint off with `artisanpack.ecommerce.features.graphql = false`.
Its schema has its own type registry, so its type names (`Product`, `Order`, …)
never collide with a host app's GraphQL schemas.

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
  `DateTime` (ISO 8601), `JSON`, `BigInt` (64-bit).
- `UserError { field, code, message }` for expected mutation failures.

## Queries

| Field | Access | Rate policy |
|---|---|---|
| `product(id)`, `productBySlug(slug)`, `products(filter, first, after)`, `search(query, first, after)` | public (storefront-visible products only) | `ecommerce.catalog.read` |
| `cart(token)` | anyone holding the token | `ecommerce.cart.mutate` |
| `me`, `myOrders` | signed-in shopper, token with `ecommerce:storefront` (or admin) | `ecommerce.admin.mutate` |
| `order(id)` | `ecommerce.order.view`, or the shopper who owns it | `ecommerce.admin.mutate` |
| `orders`, `refund`, `customer`, `customers`, `promotion`, `promotions`, `taxClasses`, `taxRates`, `shippingZones`, `shippingZone`, `inventoryItems`, `orderSubstatuses(systemStatus)`, `webhookSubscriptions`, `webhookSubscription`, `notificationTemplates`, `notificationTemplate` | the matching `ecommerce.{resource}.{action}` ability | `ecommerce.admin.mutate` |

## Mutations

Each mutation mirrors a REST endpoint and calls the same service with the same
validation, auth, and rate-limit policy. Inputs use the REST payload's
snake_case keys. Each mutation takes one `input` argument and returns
`{ …, errors: [UserError!]!, clientMutationId }`.

| Mutation | REST |
|---|---|
| `createCart` | `POST carts` |
| `addToCart` | `POST carts/{token}/items` (price resolved server-side) |
| `updateCartItem` / `removeCartItem` | `PATCH` / `DELETE carts/{token}/items/{item}` |
| `applyCoupon` / `removeCoupon` | `POST carts/{token}/coupons` / `DELETE carts/{token}/coupons/{code}` |
| `issueRefund` | `POST orders/{order}/refunds` |
| `cancelOrder` | `POST orders/{order}/cancel` |
| `addOrderNote` | `POST orders/{order}/notes` |
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
refused refund, a notification template the sandbox rejects) come back in
`errors`. Auth, not-found, and rate-limit failures
are top-level GraphQL errors with `extensions.code` set to `UNAUTHENTICATED`,
`FORBIDDEN`, `NOT_FOUND`, `RATE_LIMITED` (with `retry_after`), or
`BAD_USER_INPUT`.

`placeOrder`, `moveKanbanCard`, and the other mutations in engine spec §10.3
arrive with the checkout and kanban services behind them. The review, license,
and preference fields (`submitReview`, `moderateReview`, `validateLicense`,
`revokeLicense`, `updateMyNotificationPreferences`, `digitalFiles`,
`licenseKey`) are REST-only for now. Their services exist, so they can be
added the same way as the notification-template fields.

## Authentication

The endpoint serves public and authenticated fields in the same request, so it
resolves credentials without requiring them: Sanctum bearer tokens, Sanctum
cookie sessions, and signed service requests all work (see
[api-auth.md](api-auth.md)). Each field then enforces its own ability and token
scope. An `Idempotency-Key` header is honoured when sent (and optional), so a
retried mutation request replays its first response.

Before anything executes, each operation is checked:

- **GET runs queries only.** Mutations must be sent with POST, so a
  cross-site `GET` can't perform one with an admin's cookie session.
- **Depth:** at most `graphql.max_depth` levels (default 10).
- **Complexity:** at most `graphql.max_complexity` cost (default 5,000). Each
  field costs 1, a connection multiplies its selection by `first`, and a
  relation list multiplies by `graphql.list_complexity_factor` (default 5), so
  nested lists and aliases are priced by what they would load. A 25-product
  listing with prices and variants costs about 1,400.
- **Batching:** at most `graphql.max_batch` operations per request (default 10),
  whether sent as JSON, form fields, or a multipart `operations` field.

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

They're delivered over Laravel broadcasting (Reverb, Pusher, …), not over HTTP.
Set `ECOMMERCE_GRAPHQL_SUBSCRIPTIONS=true` with a configured broadcaster. Each
event is broadcast as `{field}` with a body shaped like a GraphQL response:

```js
Echo.private('ecommerce.admin').listen('.orderStatusChanged', ({ data }) => {
  console.log(data.orderStatusChanged.order.order_number, data.orderStatusChanged.to);
});
```

The channel is authorized by the `ecommerce.order.viewAny` ability.
`kanbanCardMoved` (`{ card, from_column_id, to_column_id, board_id }`) is
broadcast on `private-ecommerce.kanban.board.{boardId}` when
`ECOMMERCE_KANBAN_BROADCAST=true`; that channel needs the `kanbanBoard.view`
ability (see [kanban.md](kanban.md)). `orderPlaced` joins the list with the
checkout service that fires it.

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
