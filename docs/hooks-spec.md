# Ecommerce Hooks Spec

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

## Policy filters (MEDIUM)

Every Laravel Gate ability check in `src/Policies/*.php` routes through
`ap.ecommerce.abilities.{resource}.{action}` filters, matching the pattern used
in `cms-framework` Wave 4c and `media-library`.

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
- [ ] Policy ability filters wired in every policy
- [ ] README "events, and hooks" claim links to this reference
