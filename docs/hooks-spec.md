# Ecommerce Hooks Spec

> For the hooks the engine actually fires today (names, arguments, return types, and where each one fires), see the authoritative [hooks reference](hooks.md). This page is the original design-time spec.

Design-time specification for every extension hook this package fires. Locked
in before implementation so nothing drifts as subsystems land.

Tracked in [#97](https://github.com/ArtisanPack-UI/ecommerce/issues/97). Depends
on `artisanpack-ui/hooks` v1.2.0
([hooks#13](https://github.com/ArtisanPack-UI/hooks/issues/13), Wave 0 of the
cross-package hooks standardization initiative).

## Conventions

- Every hook name follows `ap.ecommerce.{camelCase}...`.
- **Filters** return a value (possibly modified); **actions** return nothing.
- Every hook ships with a test that registers a subscriber and asserts payload.
- Every hook is documented in the README as it lands.

## Cart lifecycle (HIGH)

| Hook | Type | Where | Payload |
|---|---|---|---|
| `ap.ecommerce.cart.itemAdding` | filter | before add | `(array $line, Cart $cart)` — return modified line or `null` to abort |
| `ap.ecommerce.cart.itemAdded` | action | after add | `(CartItem $item, Cart $cart)` |
| `ap.ecommerce.cart.itemUpdated` | action | after quantity/variant change | `(CartItem $item, Cart $cart)` |
| `ap.ecommerce.cart.itemRemoved` | action | after remove | `(CartItem $item, Cart $cart)` |
| `ap.ecommerce.cart.merging` | filter | guest→user cart merge | `(Cart $guestCart, Cart $userCart)` |
| `ap.ecommerce.cart.cleared` | action | on clear/expiry | `(Cart $cart, string $reason)` |

## Order lifecycle (HIGH)

| Hook | Type | Where | Payload |
|---|---|---|---|
| `ap.ecommerce.order.placing` | filter | before order persist | `(array $attributes, Cart $cart)` |
| `ap.ecommerce.order.placed` | action | after order created | `(Order $order)` |
| `ap.ecommerce.order.paid` | action | on payment settled | `(Order $order, Payment $payment)` |
| `ap.ecommerce.order.fulfilling` | action | before fulfillment | `(Order $order)` |
| `ap.ecommerce.order.fulfilled` | action | after fulfillment | `(Order $order)` |
| `ap.ecommerce.order.shipped` | action | on shipment | `(Order $order, Shipment $shipment)` |
| `ap.ecommerce.order.delivered` | action | on delivery confirmation | `(Order $order)` |
| `ap.ecommerce.order.cancelling` | action | before cancel | `(Order $order, string $reason)` |
| `ap.ecommerce.order.cancelled` | action | after cancel | `(Order $order)` |
| `ap.ecommerce.order.refunded` | action | after refund | `(Order $order, Refund $refund)` |
| `ap.ecommerce.order.statusChanged` | action | any status transition | `(Order $order, string $from, string $to)` |

## Pricing pipeline (HIGH)

| Hook | Type | Where | Payload |
|---|---|---|---|
| `ap.ecommerce.pricing.itemPrice` | filter | per-line-item price calc | `(Money $price, CartItem $item, Cart $cart)` |
| `ap.ecommerce.pricing.subtotal` | filter | subtotal calc | `(Money $subtotal, Cart $cart)` |
| `ap.ecommerce.pricing.total` | filter | final total | `(Money $total, Cart $cart, array $breakdown)` |
| `ap.ecommerce.pricing.discountApplied` | action | after discount application | `(Discount $discount, Cart $cart, Money $amount)` |
| `ap.ecommerce.pricing.registeredDiscountTypes` | filter | discount-type registry | `(array $types)` |

## Tax calculation (HIGH)

| Hook | Type | Where | Payload |
|---|---|---|---|
| `ap.ecommerce.tax.calculating` | filter | before tax calc | `(TaxContext $context, Cart $cart)` — swap provider entirely |
| `ap.ecommerce.tax.rates` | filter | rate resolution | `(array $rates, TaxContext $context)` |
| `ap.ecommerce.tax.calculated` | filter | after calc | `(TaxBreakdown $breakdown, Cart $cart)` |

## Shipping (HIGH)

| Hook | Type | Where | Payload |
|---|---|---|---|
| `ap.ecommerce.shipping.registeredMethods` | filter | shipping method registry | `(array $methods)` — register FedEx, USPS, custom methods |
| `ap.ecommerce.shipping.availableMethods` | filter | per-cart method filtering | `(array $methods, Cart $cart, Address $destination)` |
| `ap.ecommerce.shipping.rateCalculated` | filter | per-method rate | `(Money $rate, ShippingMethod $method, Cart $cart)` |
| `ap.ecommerce.shipping.methodSelected` | action | on customer selection | `(ShippingMethod $method, Cart $cart)` |

## Payment gateways (HIGH)

| Hook | Type | Where | Payload |
|---|---|---|---|
| `ap.ecommerce.payment.registeredGateways` | filter | gateway registry | `(array $gateways)` — Stripe, Braintree, custom |
| `ap.ecommerce.payment.availableGateways` | filter | per-cart gateway filtering | `(array $gateways, Cart $cart)` |
| `ap.ecommerce.payment.charging` | action | before charge | `(Gateway $gateway, Money $amount, Order $order)` |
| `ap.ecommerce.payment.succeeded` | action | after charge | `(Payment $payment)` |
| `ap.ecommerce.payment.failed` | action | on failure | `(Gateway $gateway, Throwable $e, Order $order)` |
| `ap.ecommerce.payment.webhookReceived` | action | inbound gateway webhook | `(array $payload, string $gatewaySlug)` |

## Product / variant lifecycle (MEDIUM)

| Hook | Type | Where | Payload |
|---|---|---|---|
| `ap.ecommerce.product.saving` / `.saved` | action | Product model events | `(Product $product)` |
| `ap.ecommerce.product.published` / `.unpublished` | action | visibility transitions | `(Product $product)` |
| `ap.ecommerce.product.deleted` | action | on delete | `(Product $product)` |
| `ap.ecommerce.variant.saved` | action | Variant model events | `(Variant $variant, Product $product)` |
| `ap.ecommerce.product.registeredTypes` | filter | product-type registry | `(array $types)` — simple, variant, digital, subscription, bundle |
| `ap.ecommerce.product.searchableData` | filter | Scout `Product::toSearchableArray()` | `(array $data, Product $product)` — add fields to a dedicated search index; see [search.md](search.md) |

## Inventory (MEDIUM)

| Hook | Type | Where | Payload |
|---|---|---|---|
| `ap.ecommerce.inventory.adjusting` | filter | before adjustment | `(int $delta, InventoryItem $item, string $reason)` |
| `ap.ecommerce.inventory.adjusted` | action | after adjustment | `(InventoryItem $item, int $delta, int $newLevel)` |
| `ap.ecommerce.inventory.lowStock` | action | when below threshold | `(InventoryItem $item, int $level)` |
| `ap.ecommerce.inventory.outOfStock` | action | on hitting zero | `(InventoryItem $item)` |

## Customer lifecycle (MEDIUM)

| Hook | Type | Where | Payload |
|---|---|---|---|
| `ap.ecommerce.customer.registered` | action | first-time customer | `(Customer $customer)` |
| `ap.ecommerce.customer.firstOrder` | action | on first order paid | `(Customer $customer, Order $order)` |
| `ap.ecommerce.customer.becameVip` | action | on VIP threshold cross (spend/order count) | `(Customer $customer, string $reason)` |

## Outbound webhooks (MEDIUM)

| Hook | Type | Where | Payload |
|---|---|---|---|
| `ap.ecommerce.webhook.subscribing` | filter | before a subscription is inserted | `(array $attributes)` — return `null` to abort |
| `ap.ecommerce.webhook.delivering` | filter | before a delivery is signed and sent | `(array $payload, WebhookSubscription $subscription, string $event)` |
| `ap.ecommerce.webhook.delivered` | action | on a 2xx response | `(WebhookDelivery $delivery)` |
| `ap.ecommerce.webhook.failed` | action | on a non-2xx response or transport error | `(WebhookDelivery $delivery, Throwable $reason)` |
| `ap.ecommerce.webhook.subscriptionDisabled` | action | when the consecutive-failure ceiling is hit | `(WebhookSubscription $subscription)` |

See [webhooks.md](webhooks.md).

## API augmentation (MEDIUM)

| Hook | Type | Where | Payload |
|---|---|---|---|
| `ap.ecommerce.api.resource.{name}` | filter | every REST resource (and so every GraphQL object) | `(array $data, Model $subject, Request $request)` |
| `ap.ecommerce.api.list.{name}` | filter | before a REST listing is serialized | `(array $items, Builder $query, Request $request)` |
| `ap.ecommerce.graphql.extend` | filter | GraphQL schema build | `(array $schema, TypeRegistry $registry)` — add types and fields; see [graphql.md](graphql.md) |

## Policy filters (MEDIUM)

Every ability check — the REST `ecommerce.can` middleware, the policies in
`src/Policies/*.php`, and the GraphQL resolvers — routes through
`ap.ecommerce.abilities.{resource}.{action}`, matching the pattern used in
`cms-framework` Wave 4c and `media-library`. Payload:
`(bool $allowed, Authenticatable $user, Request $request, mixed $subject)`,
where `$subject` is the model when checked through a policy or GraphQL and the
request when checked by REST middleware. A filter can't widen what a scoped
Sanctum token allows. See [api-auth.md](api-auth.md).

## Landing checklist

Check items off as each subsystem lands. Each domain has its own tracking
sub-issue linked back to #97.

- [ ] Cart lifecycle hooks fired + tested + documented
- [ ] Order lifecycle hooks fired + tested + documented
- [ ] Pricing pipeline hooks fired + tested + documented
- [ ] Tax calculation hooks fired + tested + documented
- [ ] Shipping hooks fired + tested + documented
- [ ] Payment gateway hooks fired + tested + documented
- [ ] Product / variant lifecycle hooks fired + tested + documented
- [ ] Inventory hooks fired + tested + documented
- [ ] Customer lifecycle hooks fired + tested + documented
- [x] Policy ability filters wired in every policy
- [x] Outbound webhook hooks fired + tested + documented
- [x] API augmentation hooks (`api.resource`, `api.list`, `graphql.extend`) fired + tested + documented
- [ ] README "events, and hooks" claim links to this reference
