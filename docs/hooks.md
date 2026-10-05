# Hooks reference

Every action and filter the engine fires, as implemented in `src/`. Hooks run
through [`artisanpack-ui/hooks`](https://github.com/ArtisanPack-UI/hooks) v1.2+
(`addAction()` / `doAction()`, `addFilter()` / `applyFilters()`).

This page is the **authoritative reference** for what the code fires today.
[`hooks-spec.md`](hooks-spec.md) is the original design-time spec it grew from.
Where the two differ, this page is right.

## Conventions

- **Names** are `ap.ecommerce.{domain}.{camelCaseEvent}`, for example
  `ap.ecommerce.cart.itemAdded` or `ap.ecommerce.order.statusChanged`. The
  parent plan's `ecommerce.order.placed`-style names (§6.3) were renamed to this
  scheme so that they don't collide with other ArtisanPack UI packages.
- **Tense** tells you the kind. A present-participle name (`…ing`: `cart.creating`,
  `tax.calculating`, `review.submitting`) is usually a **filter** that runs
  *before* the engine acts. Its return value changes what happens. A past-tense
  name (`…ed`: `cart.itemAdded`, `order.refunded`) is an **action** that fires
  *after* the change is committed.
- **Dynamic names.** Some hooks are built from a variable segment:
  `ap.ecommerce.abilities.{resource}.{action}`,
  `ap.ecommerce.api.resource.{resource}`, `ap.ecommerce.api.list.{resource}`,
  and `ap.ecommerce.gateway.{provider}.webhook_received`.
- **Filters must return the same type they receive.** Most engine call sites
  validate the return value and either fall back to the original, discard
  invalid entries, or throw `UnexpectedValueException` (noted per hook below).
- **After commit.** Hooks marked *after commit* fire once the outermost
  database transaction commits (immediately when none is open), so a
  rolled-back write never announces itself.
- **Priority.** Lower numbers run first (default `10`).

### Hooks vs. Laravel events

The two are deliberately complementary (parent plan §6.4):

| | Hooks (this page) | [Laravel events](events.md) |
|---|---|---|
| Timing | Synchronous, in the request | Dispatched synchronously; listeners may queue themselves |
| Can change data | Yes (filters) | No |
| Can abort | Yes: throw from a listener | No |
| Use for | Display transforms, validation short-circuits, in-request side effects | Emails, webhooks, external syncs, analytics |

Many lifecycle points fire both, for example `ap.ecommerce.order.refunded` and
`OrderRefunded`. The action always fires first.

```php
// AppServiceProvider::boot()
addFilter( 'ap.ecommerce.shipping.rateCalculated', function ( Money $amount, ShippingMethod $method, Cart $cart ): Money {
    return $cart->customer?->meta['vip'] ?? false ? $amount->multiply( '0' ) : $amount;
} );

addAction( 'ap.ecommerce.order.statusChanged', function ( Order $order, string $from, string $to ): void {
    Log::channel( 'ecommerce' )->info( 'order.status', compact( 'from', 'to' ) + [ 'order_id' => $order->id ] );
} );
```

All hooks below are available since **1.0.0**.

---

## Cart

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.cart.creating` | filter | `array $attributes`: attributes for the new cart | `array` | `Services/CartService::create()` |
| `ap.ecommerce.cart.created` | action | `Cart $cart` | | `Services/CartService::create()` |
| `ap.ecommerce.cart.itemAdding` | filter | `array $line` (`product_id`, `product_variant_id`, `quantity`, `unit_price_amount`, `unit_price_currency`, `options`), `Cart $cart` | `array\|null`. Return `null` to veto the add. | `Services/CartService::addItem()` |
| `ap.ecommerce.cart.itemAdded` | action | `CartItem $item`, `Cart $cart` | | `Services/CartService::addItem()` |
| `ap.ecommerce.cart.itemUpdated` | action | `CartItem $item`, `Cart $cart`. Fires when a quantity is set, or when an add merges into an existing line. | | `Services/CartService` |
| `ap.ecommerce.cart.itemRemoved` | action | `CartItem $item` (already deleted), `Cart $cart` | | `Services/CartService::removeItem()` |
| `ap.ecommerce.cart.tokenRotated` | action | `Cart $cart`, `string $previousToken` | | `Services/CartService::rotateToken()` |
| `ap.ecommerce.cart.merging` | filter | `Cart $result`, `Cart $guestCart`, `Cart $destinationCart` | `Cart`. Non-`Cart` returns are ignored. | `Services/CartMergeService` |
| `ap.ecommerce.cart.merged` | action | `Cart $result`, `int $guestItemsCarried` | | `Services/CartMergeService` |
| `ap.ecommerce.cart.cleared` | action | `Cart $cart` (now empty, totals zeroed), `string $reason` (`cleared` by default; `converted` when the cart becomes an order) | | `Services/CartService::clear()` |
| `ap.ecommerce.cart.abandoned` | action | `Cart $cart`. Fires once per cart that `ecommerce:flag-abandoned-carts` flags, before `CartAbandoned`. | | `Console/Commands/FlagAbandonedCartsCommand` |
| `ap.ecommerce.cart.crossSells` | filter | `Collection<int, Product> $products` (hand-picked cross-sells of the cart's products, minus what's already in the cart), `Cart $cart`, `int $limit` | `Collection`. Anything else is ignored. | `Catalog/RelatedProducts::crossSellsForCart()` (`GET carts/{cart}/cross-sells`) |

## Checkout

The checkout state machine (`not_started` → `addressing` →
`shipping_selection` → `payment_selection` → `payment_pending` →
`payment_confirmed` → `completed`, or `failed`) lives in
`Services/CheckoutService`.

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.checkout.started` | action | `Cart $cart`. Fires only the first time checkout starts for the cart. | | `Services/CheckoutService::start()` |
| `ap.ecommerce.checkout.canTransitionTo` | filter | `true`, `Cart $cart`, `string $to` (target `checkout_state`) | `true` to allow. Anything else throws `CheckoutException` (`transition-blocked`, HTTP 409). | `Services/CheckoutService::transition()` |
| `ap.ecommerce.checkout.addressCaptured` | action | `Cart $cart`, `Address $address`, `string $kind` (`shipping` or `billing`). Fires for `shipping` when one was given, then always for `billing` (the shipping address when no billing address was given). | | `Services/CheckoutService::setAddress()` |
| `ap.ecommerce.checkout.availableGateways` | filter | `Collection<int, PaymentGateway> $gateways` (from `payment.availableGateways`), `Cart $cart` | `Collection`. A non-`Collection` return is ignored; gateways not in the original set are dropped. | `Services/CheckoutService::availableGateways()` |
| `ap.ecommerce.checkout.paymentInitiated` | action | `Cart $cart`, `PaymentGateway $gateway`, `PaymentSession $session` | | `Services/CheckoutService::createPaymentSession()` |
| `ap.ecommerce.checkout.finalized` | action | `Order $order`, `Cart $cart`. Fires when the payment is captured, or after commit when a zero-total order is placed without a gateway. | | `Services/CheckoutService::finalize()` |
| `ap.ecommerce.checkout.failed` | action | `Cart $cart`, `Throwable $reason`. Fires when the fraud check blocks the payment, or when the captured amount or currency doesn't match the order total. | | `Services/CheckoutService` |

## Pricing and promotions

`pricing.itemPrice`, `pricing.subtotal`, `pricing.total` and
`pricing.priceDisplay` fall back to the original amount when a listener
returns something other than a `Money` in the same currency, or a negative
amount.

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.currency.resolved` | filter | `string $currency` (the shopper's resolved ISO code, or the base currency), `Request $request` | `string`. A currency that isn't enabled is ignored. | `Services/SessionCurrencyResolver::resolve()` |
| `ap.ecommerce.pricing.itemPrice` | filter | `Money $price` (unit price), `CartItem $item`, `Cart $cart` | `Money` | `Services/StorefrontCartService` (cart recalculation) |
| `ap.ecommerce.pricing.subtotal` | filter | `Money $subtotal`, `Cart $cart` | `Money` | `Services/StorefrontCartService::refreshTotals()` |
| `ap.ecommerce.pricing.total` | filter | `Money $total`, `Cart $cart`, `array{subtotal: Money, discount: Money, tax: Money, shipping: Money} $breakdown` | `Money` | `Services/StorefrontCartService::refreshTotals()` |
| `ap.ecommerce.pricing.priceDisplay` | filter | `Money $price`, `Product\|ProductVariant $priceable`, `?Customer $customer` | `Money` | `Pricing/PriceDisplayResolver` |
| `ap.ecommerce.promotions.candidates` | filter | `array<Promotion> $candidates`, `Cart $cart`, `?string $couponCode` | `array<Promotion>`. Unusable entries are dropped. | `Services/PromotionEngine` |
| `ap.ecommerce.pricing.discountApplied` | action | `Promotion $promotion`, `Cart $cart`, `Money $amount` (discount granted by this promotion) | | `Services/PromotionEngine` |

## Tax

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.tax.calculating` | filter | `TaxContext $context`, `Cart $cart` | `TaxContext`. Anything else throws `UnexpectedValueException`. | `Services/TaxService` |
| `ap.ecommerce.tax.calculated` | filter | `TaxResult $result`, `Cart $cart` | `TaxResult`. Anything else throws `UnexpectedValueException`. | `Services/TaxService` |
| `ap.ecommerce.tax.rates` | filter | `array<TaxRate> $rates` (matched, by priority), `TaxContext $context` | `array<TaxRate>`. Non-`TaxRate` entries are dropped. | `Tax/ManualTaxProvider` |

## Shipping

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.shipping.availableMethods` | filter | `array<ShippingRate> $rates`, `Cart $cart`, `Address $destination` | `array<ShippingRate>`. Non-`ShippingRate` entries are dropped. | `Shipping/ZoneShippingRateProvider` |
| `ap.ecommerce.shipping.rateCalculated` | filter | `Money $amount`, `ShippingMethod $method`, `Cart $cart` | `Money`. A non-`Money` return removes that rate. | `Shipping/ZoneShippingRateProvider` |
| `ap.ecommerce.shipping.shipmentCreated` | action | `Shipment $shipment`, `Order $order` | | `Services/ShipmentService` |
| `ap.ecommerce.shipping.trackingUpdated` | action | `Shipment $shipment`, `TrackingStatus $status` | | `Services/ShipmentService`, `Shipping/LocalPickupHandoff` |
| `ap.ecommerce.shipping.pickupReady` | action | `Shipment $shipment`, `Order $order`, `string $payload` (the one-time pickup-code payload for a QR code or email) | | `Shipping/LocalPickupHandoff::issue()` |
| `ap.ecommerce.shipping.methodSelected` | action | `ShippingRate $rate`, `Cart $cart`, `?ShippingMethod $method` (`null` for a rate from a provider without a stored method) | | `Services/StorefrontCartService::selectShippingMethod()` |

## Payments and fraud

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.fraud.assessing` | filter | `array $context` (`shipping`, `provider_key`, `session_ref`), `Cart $cart` | Currently **observe-only**: the return value is not used. | `Services/PaymentOrchestrator` |
| `ap.ecommerce.fraud.assessed` | filter | `FraudDecision $decision`, `Cart $cart`, `PaymentSession $session` | `FraudDecision`. Non-`FraudDecision` returns are ignored. | `Services/PaymentOrchestrator` |
| `ap.ecommerce.fraud.blocked` | action | `Order $order`, `FraudDecision $decision` | | `Services/PaymentOrchestrator` |
| `ap.ecommerce.payment.charging` | action | `PaymentGateway $gateway`, `Money $amount`, `Order $order` | | `Services/PaymentOrchestrator` (before capture) |
| `ap.ecommerce.payment.succeeded` | action | `PaymentResult $result`, `Order $order`. Fires after the capture is committed; a listener that throws is logged and the capture stands. | | `Services/PaymentOrchestrator` |
| `ap.ecommerce.order.paid` | action | `Order $order`, `PaymentResult $result`. Fires right after `payment.succeeded`, with the same isolation. The engine's customer-milestone listener subscribes to it. | | `Services/PaymentOrchestrator` |
| `ap.ecommerce.payment.failed` | action | `PaymentGateway $gateway`, `Throwable $reason`, `Order $order` | | `Services/PaymentOrchestrator` (declined capture) |
| `ap.ecommerce.payment.refunding` | filter | `Money $amount`, `Order $order`, `?string $reason` | `Money`. The currency and remaining-refundable guards run again on the result. | `Services/RefundService` |
| `ap.ecommerce.payment.refunded` | action | `RefundResult $result`, `Order $order`. After commit. | | `Services/RefundService` |
| `ap.ecommerce.payment.availableGateways` | filter | `array<string, PaymentGateway> $gateways` (every registered gateway, by key), `Cart $cart` | `array<string, PaymentGateway>`. A non-array return is ignored; entries that aren't the registered gateway for their key are dropped. | `Registries/PaymentGatewayRegistry::availableFor()` |
| `ap.ecommerce.payment.clientConfig` | filter | `array $config` (`driver`, `flow`, `publishable_key`, `client_secret`, `redirect_url`, `options`; `null` when the gateway can't render a storefront step), `PaymentGateway $gateway`, `Cart $cart`, `PaymentSession $session` | `array\|null`. A non-array return means no client config; otherwise `gateway` (the gateway key) is added when missing. | `Support/ClientPaymentConfig` |
| `ap.ecommerce.webhook_received` | action | `string $provider`, `WebhookResult $result`, `Request $request`. Fires once per verified, non-duplicate inbound event. | | `Http/Controllers/WebhookController` |
| `ap.ecommerce.gateway.{provider}.webhook_received` | action | `WebhookResult $result`, `Request $request` | | `Http/Controllers/WebhookController` |
| `ap.ecommerce.payment.webhookReceived` | action | `array $payload` (the verified provider payload), `string $provider`. Fires after the two hooks above. | | `Http/Controllers/WebhookController` |

## Orders

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.order.placing` | filter | `array $attributes` (the new order's attributes, built from the cart), `Cart $cart` | `array`. Anything else throws `UnexpectedValueException`. | `Services/OrderPlacementHooks::placing()`, from `Services/OrderPlacementService` |
| `ap.ecommerce.order.number` | filter | `string $number` (from the configured order-number generator), `Order $order` (not yet saved) | `string`. Trimmed and cut to 50 characters; an empty or non-string return keeps the generated number. | `Services/OrderPlacementService` |
| `ap.ecommerce.order.placed` | action | `Order $order`. After commit, once the order, its lines, and the converted cart are persisted, before `OrderPlaced`, `CartCompleted`, `PromotionApplied`, and `CouponRedeemed`. | | `Services/OrderPlacementHooks::placed()`, from `Services/OrderPlacementService` |
| `ap.ecommerce.order.statusChanged` | action | `Order $order`, `string $from`, `string $to`. After commit. | | `Services/OrderStatusMachine::transition()` |
| `ap.ecommerce.order.canTransitionSubstatus` | filter | `bool $allowed`, `Order $order`, `?OrderSubstatus $from`, `OrderSubstatus $to`, `?int $boardId` | `bool`. `false` throws `SubstatusTransitionRejectedException`. | `Services/OrderStatusMachine::setSubstatus()` |
| `ap.ecommerce.order.substatusChanged` | action | `Order $order`, `?OrderSubstatus $from`, `OrderSubstatus $to`, `?int $boardId`. After commit. | | `Services/OrderStatusMachine::setSubstatus()` |
| `ap.ecommerce.order.statusAuditDrift` | action | `array $row`: one drift finding (`order_id`, `order_system_status`, `substatus_id`, `substatus_system_status`, …) | | `Console/Commands/AuditOrderStatusCommand` |
| `ap.ecommerce.order.editing` | filter | `array $edit` (the requested edit), `Order $order` (locked) | `array` | `Services/OrderEditService` |
| `ap.ecommerce.order.recomputingTotals` | filter | `Order $order` | `Order` (mutate in place, or return an instance with the same key) | `Services/OrderEditService` |
| `ap.ecommerce.order.edited` | action | `Order $order`, `array $diff`, `OrderEdit $edit`. After commit. | | `Services/OrderEditService` |
| `ap.ecommerce.order.fulfilling` | action | `Order $order` (row-locked), before the shipment row is created. Throw to refuse the shipment. | | `Services/ShipmentService::create()` |
| `ap.ecommerce.order.shipped` | action | `Order $order`, `Shipment $shipment` | | `Services/ShipmentService` |
| `ap.ecommerce.order.itemFulfilled` | action | `Order $order`, `OrderItem $item` (once per line the shipment completes) | | `Services/ShipmentService` |
| `ap.ecommerce.order.fulfilled` | action | `Order $order` (every line fulfilled) | | `Services/ShipmentService` |
| `ap.ecommerce.order.delivered` | action | `Order $order`, `Shipment $shipment` | | `Services/ShipmentService`, `Shipping/LocalPickupHandoff` |
| `ap.ecommerce.order.refunded` | action | `Order $order`, `Refund $refund`. After commit, after `payment.refunded`. | | `Services/RefundService` |
| `ap.ecommerce.order.cancelling` | action | `Order $order`, `string $reason` (before the void, the reservation release, and the status change) | | `Services/OrderCancellationService` |
| `ap.ecommerce.order.cancelled` | action | `Order $order` (cancelled). After commit. | | `Services/OrderCancellationService` |
| `ap.ecommerce.order.noteAdded` | action | `Order $order`, `OrderNote $note` | | `Services/OrderNoteService` |
| `ap.ecommerce.order.noteDeleted` | action | `OrderNote $note` (already deleted) | | `Services/OrderNoteService` |
| `ap.ecommerce.orderSubstatus.created` | action | `OrderSubstatus $substatus` | | `Services/OrderSubstatusService` |
| `ap.ecommerce.orderSubstatus.updated` | action | `OrderSubstatus $substatus`, `array<int, string> $changedFields` | | `Services/OrderSubstatusService` |
| `ap.ecommerce.orderSubstatus.reordered` | action | `string $systemStatus`, `Collection<int, OrderSubstatus> $substatuses` (new order) | | `Services/OrderSubstatusService` |
| `ap.ecommerce.orderSubstatus.deleted` | action | `OrderSubstatus $substatus` (already deleted) | | `Services/OrderSubstatusService` |

> **`ap.ecommerce.order.placed`.** Checkout places the order through
> `OrderPlacementService`, which fires this action. The engine listens for
> it to send the order-confirmation notification, route the order onto
> kanban boards, and so on. Code that creates orders some other way must
> fire it itself, with `doAction( 'ap.ecommerce.order.placed', $order )`,
> once the order and its lines are persisted.

## Customers

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.customer.registered` | action | `Customer $customer` (a new customer row was created) | | `Services/CustomerService` |
| `ap.ecommerce.customer.userLinked` | action | `Customer $customer`, `Authenticatable $user` | | `Services/CustomerService` |
| `ap.ecommerce.customer.updated` | action | `Customer $customer`, `array<int, string> $changedFields`. After commit; only when a field actually changed. | | `Services/CustomerService::updateProfile()` |
| `ap.ecommerce.customer.firstOrder` | action | `Customer $customer`, `Order $order`. Fires when the customer's first order is paid. | | `Listeners/TrackCustomerMilestones` (on `order.paid`) |
| `ap.ecommerce.customer.becameVip` | action | `Customer $customer`, `string $reason` (`order_count` or `lifetime_spend`, whichever VIP threshold was crossed) | | `Listeners/TrackCustomerMilestones` (on `order.paid`) |
| `ap.ecommerce.customer.orderClaimed` | action | `Customer $customer`, `Order $order` (a guest order was claimed) | | `Services/CustomerClaimService` |
| `ap.ecommerce.customer.groups` | filter | `array<string> $groups` (defaults to `customers.meta.groups`), `Customer $customer` | `array<string>` | `Promotions/Conditions/CustomerInGroupCondition` |
| `ap.ecommerce.customer.noteAdded` | action | `Customer $customer`, `CustomerNote $note` | | `Services/CustomerNoteService` |
| `ap.ecommerce.customer.noteDeleted` | action | `CustomerNote $note` (already deleted) | | `Services/CustomerNoteService` |
| `ap.ecommerce.customer.addressAdded` | action | `Customer $customer`, `CustomerAddress $address` | | `Services/CustomerAddressService` |
| `ap.ecommerce.customer.addressUpdated` | action | `Customer $customer`, `CustomerAddress $address`, `array<int, string> $changedFields` | | `Services/CustomerAddressService` |
| `ap.ecommerce.customer.addressDeleted` | action | `Customer $customer`, `CustomerAddress $address` (already deleted) | | `Services/CustomerAddressService` |
| `ap.ecommerce.customer.deleted` | action | `Customer $customer` (already deleted; attributes as they were, so satellites can erase their own copies), `array<string, int> $summary` (`orders`, `addresses`, `notification_preferences`, `claim_attempts`, `notes`, `carts`, `reviews`, `promotion_usages`). Fires once the outermost transaction commits. | | `Services/CustomerService::delete()` |

## Activity log

Event types and payloads are listed in [activity-log.md](activity-log.md#event-types).

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.activity.recording` | filter | `array $attributes` (`subject_type`, `subject_id`, `actor_user_id`, `event_type`, `payload`), `Model $subject` | `array`; a falsy value skips the entry | `Services/ActivityLogService::record()` |
| `ap.ecommerce.activity.recorded` | action | `ActivityLogEntry $entry`, `Model $subject` | | `Services/ActivityLogService::record()` |

## Inventory

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.inventory.adjusting` | filter | `int $delta`, `InventoryItem $item`, `string $reason` | `int`. `0` makes the adjustment a no-op. | `Services/InventoryService::adjust()` |
| `ap.ecommerce.inventory.adjusted` | action | `InventoryItem $item`, `int $delta`, `int $newOnHand` | | `Services/InventoryService::adjust()` |
| `ap.ecommerce.inventory.lowStock` | action | `InventoryItem $item`, `int $newOnHand`. Fires when on-hand crosses `low_stock_threshold`. | | `Services/InventoryService` |
| `ap.ecommerce.inventory.outOfStock` | action | `InventoryItem $item`. Fires when on-hand drops from above zero to zero or below. | | `Services/InventoryService` |
| `ap.ecommerce.inventory.reserved` | action | `InventoryReservation $reservation` | | `Services/InventoryService::reserve()` |
| `ap.ecommerce.inventory.reservationReleased` | action | `InventoryReservation $snapshot` (a clone; the row is already deleted) | | `Services/InventoryService::releaseExpired()` |

## Products and search

The lifecycle hooks fire from the models, so they run for every write path
(`ProductService`, imports, raw Eloquent). All but `.saving` run once the
surrounding database transaction commits (immediately when there is none).
See [products.md](products.md).

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.product.saving` | action | `Product $product` | | `Models/Product` (Eloquent `saving`) |
| `ap.ecommerce.product.saved` | action | `Product $product` | | `Models/Product` (Eloquent `saved`) |
| `ap.ecommerce.product.published` | action | `Product $product`. Fires when a product is created as, or changes to, `active`. | | `Models/Product` |
| `ap.ecommerce.product.unpublished` | action | `Product $product`. Fires when status changes from `active` to anything else (`draft` or `archived`), i.e. the product leaves the storefront. | | `Models/Product` |
| `ap.ecommerce.product.deleted` | action | `Product $product` | | `Models/Product` (Eloquent `deleted`) |
| `ap.ecommerce.variant.saved` | action | `ProductVariant $variant`, `Product $product` | | `Models/ProductVariant` (Eloquent `saved`) |
| `ap.ecommerce.product.listQuery` | filter | `Builder $query`, `array $params` (the parsed catalog filters: `category` ids, `tag` id, `currency`, `price` `[min, max]`, `attributes`, `in_stock`, `on_sale`; only those the request set) | `Builder` | `Catalog/CatalogQuery::builder()` (`GET products`, `GET categories/{category}/products`) |
| `ap.ecommerce.product.viewed` | action | `Product $product`, `?Customer $customer` (the viewer, when signed in) | | `Catalog/ProductViews::record()` (`POST products/{product}/views`) |
| `ap.ecommerce.product.related` | filter | `Collection<int, Product> $products`, `Product $product`, `string $type` (`related`, `upsell`, or `cross_sell`), `int $limit` | `Collection`. Anything else is ignored. | `Catalog/RelatedProducts` (`GET products/{product}/related`) |
| `ap.ecommerce.product.searchableData` | filter | `array $data` (Scout document: `id`, `name`, `slug`, `sku`, …), `Product $product` | `array` | `Models/Product::toSearchableArray()` |

## Reviews

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.review.submitting` | filter | `array $attributes` (`product_id`, `customer_id`, `order_id`, `author_name`, `author_email`, `rating`, `title`, `body`, `is_verified_purchase`, `status`), `?Order $order` | `array\|null`. A non-array vetoes the review. | `Services/ReviewService::submit()` |
| `ap.ecommerce.review.submitted` | action | `ProductReview $review` | | `Services/ReviewService` |
| `ap.ecommerce.review.moderating` | filter | `ProductReview $review` (before the `ReviewModerator` runs) | `ProductReview` | `Services/ReviewService` |
| `ap.ecommerce.review.approved` | action | `ProductReview $review` | | `Services/ReviewService` |
| `ap.ecommerce.review.rejected` | action | `ProductReview $review`, `?string $reason` | | `Services/ReviewService` |
| `ap.ecommerce.review.markedSpam` | action | `ProductReview $review` | | `Services/ReviewService` |

## Digital delivery and licenses

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.digital.tokenIssued` | action | `DigitalDownload $download`, `OrderItem $item` | | `Services/DigitalDownloadService` |
| `ap.ecommerce.digital.productUpdated` | action | `Product $product`, `DigitalFile $file` (a new file version) | | `Services/DigitalFileService` |
| `ap.ecommerce.digital.downloading` | filter | `array $context` (`mode`, `disk`, `path`, `filename`, `headers`), `DigitalDownload $download` | `array`. Can redirect the stream to another disk or path, or add response headers. | `Digital/DigitalFileStreamer` |
| `ap.ecommerce.digital.downloaded` | action | `DigitalDownload $download` (after the body finishes streaming) | | `Digital/DigitalFileStreamer` |
| `ap.ecommerce.digital.streamWatermark` | filter | `StreamedResponse $response`, `DigitalDownload $download` (stream mode only) | `StreamedResponse`. Anything else throws `UnexpectedValueException`. | `Digital/DigitalFileStreamer` |
| `ap.ecommerce.license.issued` | action | `LicenseKey $key`, `OrderItem $item`. After commit. | | `Services/LicenseService` |
| `ap.ecommerce.license.activated` | action | `LicenseActivation $activation`. After commit. | | `Services/LicenseService` |
| `ap.ecommerce.license.deactivated` | action | `LicenseKey $key`, `string $fingerprint` (the machine released). After commit; only when an activation was removed. | | `Services/LicenseService::deactivate()` |
| `ap.ecommerce.license.revoked` | action | `LicenseKey $key`, `?string $reason`. After commit. | | `Services/LicenseService` |
| `ap.ecommerce.license.validating` | filter | `array $response` (`valid`, `expires_at`, `product`, `revoked`, `reason`), `string $key`, `string $fingerprint` | `array` (the `POST /license/validate` body) | `Services/LicenseService` |

## Kanban

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.kanban.cardMoving` | filter | `array $move` (`order_id`, `board_id`, `from_column_id`, `to_column_id`, `actor_user_id`, `reason`), `OrderBoardAssignment $assignment` | `array`. A missing or non-numeric `to_column_id` rejects the move (`KanbanOperationException`). Changing it redirects the move. | `Services/KanbanBoardService` |
| `ap.ecommerce.kanban.cardMoved` | action | `Order $order`, `KanbanColumn $from`, `KanbanColumn $to`, `KanbanBoard $board`. After commit. | | `Services/KanbanBoardService` |
| `ap.ecommerce.kanban.routingBoards` | filter | `array<int> $boardIds` (boards whose routing rules match), `Order $order` | `array<int>`. Non-numeric and inactive ids are dropped. | `Services/KanbanRoutingService` |
| `ap.ecommerce.kanban.boardAssignmentAdded` | action | `OrderBoardAssignment $assignment`. After commit. | | `Services/KanbanRoutingService` |
| `ap.ecommerce.kanban.boardAssignmentRemoved` | action | `OrderBoardAssignment $assignment`. After commit. | | `Services/KanbanRoutingService` |
| `ap.ecommerce.kanban.boardReassigned` | action | `Order $order`, `array<int> $addedBoardIds`, `array<int> $removedBoardIds`. After commit. | | `Services/KanbanRoutingService::reroute()` |
| `ap.ecommerce.kanban.automationFired` | action | `KanbanAutomation $automation`, `Order $order` | | `Services/KanbanAutomationRunner` |
| `ap.ecommerce.kanban.widgetRendered` | filter | `array $payload` (widget render payload), `KanbanCardWidget $widget`, `Order $order` | `array`. Non-array returns are ignored. | `Kanban/KanbanCardRenderer` |
| `ap.ecommerce.kanban.orderTags` | filter | `array<string> $tags` (defaults to `orders.meta.tags`), `Order $order` | `array<string>` | `Kanban/Widgets/TagsWidget` |
| `ap.ecommerce.kanban.updatableOrderFields` | filter | `array<string> $fields` (defaults to `[]`), `Order $order` | `array<string>`: extra order columns the `update-order-field` automation may write | `Kanban/Triggers/UpdateOrderFieldTrigger` |

## Notifications

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.notification.templateVariables` | filter | `array $variables` (always includes `Store`), `string $templateKey`, `mixed $subject` (the order or customer, or `null` for previews) | `array` | `Notifications/NotificationDispatcher`, `Services/NotificationTemplateService` (preview) |
| `ap.ecommerce.notification.rendering` | filter | `string $body` (template source, before rendering), `string $templateKey`, `array $variables` | `string` | `Notifications/NotificationTemplateRenderer` |
| `ap.ecommerce.notification.sending` | action | `EcommerceNotification $notification`, `mixed $notifiable`, `string $channel` | | `Listeners/SendCatalogNotifications` |
| `ap.ecommerce.notification.sent` | action | `EcommerceNotification $notification`, `mixed $notifiable`, `string $channel` | | `Listeners/SendCatalogNotifications` |

## Outbound webhooks

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.webhook.subscribing` | filter | `array $attributes` (new subscription) | `array\|null`. `null` vetoes the subscription. | `Services/WebhookSubscriptionService` |
| `ap.ecommerce.webhook.delivering` | filter | `array $payload`, `WebhookSubscription $subscription`, `string $event` | `array`. This is the JSON body that gets signed and sent. | `Services/WebhookDeliveryService` |
| `ap.ecommerce.webhook.delivered` | action | `WebhookDelivery $delivery` | | `Services/WebhookDeliveryService` |
| `ap.ecommerce.webhook.failed` | action | `WebhookDelivery $delivery`, `Throwable $reason` | | `Services/WebhookDeliveryService` |
| `ap.ecommerce.webhook.subscriptionDisabled` | action | `WebhookSubscription $subscription` (the failure ceiling was reached) | | `Services/WebhookDeliveryService` |

## Settings

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.settings.updated` | action | `string $group`, `array<string, array{from, to, reset: bool}> $changes` (by setting key). Fires after commit. | | `Settings/SettingsRepository::update()` |
| `ap.ecommerce.settings.baseCurrencyChanged` | action | `string $from`, `string $to`. Fires after commit, after `settings.updated`. Historical orders keep their snapshot. | | `Settings/SettingsRepository::update()` |

## Registries

Each filter runs once, after every service provider has booted, starting
from `[]`. Return entries keyed by registry key; an entry is a class name,
an instance, or `[ 'entry' => class|instance, 'meta' => [ 'label' => … ] ]`.
Every entry goes through the registry's own `register()`. A non-array
return, an integer key, or a malformed entry throws
`UnexpectedValueException`. See [contracts.md](contracts.md).

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.payment.registeredGateways` | filter | `array $entries` (`[]`) | `array<string, mixed>` → `PaymentGatewayRegistry` | `Support/RegistryHookRegistrar::apply()` |
| `ap.ecommerce.shipping.registeredMethods` | filter | `array $entries` (`[]`) | `array<string, mixed>` → `ShippingMethodTypeRegistry` | `Support/RegistryHookRegistrar::apply()` |
| `ap.ecommerce.product.registeredTypes` | filter | `array $entries` (`[]`) | `array<string, mixed>` → `ProductTypeRegistry` | `Support/RegistryHookRegistrar::apply()` |
| `ap.ecommerce.pricing.registeredDiscountTypes` | filter | `array $entries` (`[]`) | `array<string, mixed>` → `PromotionActionRegistry` | `Support/RegistryHookRegistrar::apply()` |

## Navigation

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.adminMenu.entries` | filter | `array $entries` (resolved admin entries, sorted, inactive satellites' entries removed) | `array` (re-indexed) | `Registries/AdminMenuRegistry::all()` |
| `ap.ecommerce.accountMenu.entries` | filter | `array $entries` (`key`, `label`, `route`, `url`, `icon`, `position`), `?Customer $customer` | `array` (re-indexed) | `Registries/AccountMenuRegistry::visibleTo()` |

## Reports

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.reports.result` | filter | `array $result`, `string $key`, `?ReportRange $range`, `array $options` | `array` | `Reports/ReportRunner::run()` |

## API augmentation

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.api.resource.{resource}` | filter | `array $data`, `Model $model`, `Request $request` | `array` | `Http/Resources/EcommerceResource::toArray()`. Applies to REST, GraphQL, and webhook payloads. |
| `ap.ecommerce.api.list.{resource}` | filter | `array $items` (each already serialized), `Builder $query`, `Request $request` | `array` | `Http/Controllers/Api/V1/ApiController` (list endpoints), `ProductController`, `SearchController`, `KanbanCardController` |
| `ap.ecommerce.order.publicMetaKeys` | filter | `array<string> $keys` (default `tax_breakdown`, `prices_include_tax`, `coupon_code`, `shipping_rate`), `Order $order` | `array<string>`: the `meta` keys a non-admin request sees | `Http/Resources/OrderResource` |
| `ap.ecommerce.orderItem.publicMetaKeys` | filter | `array<string> $keys` (default `free_item`, `promotion_id`), `OrderItem $item` | `array<string>` | `Http/Resources/OrderItemResource` |
| `ap.ecommerce.customer.publicMetaKeys` | filter | `array<string> $keys` (default `[]`), `Customer $customer` | `array<string>` | `Http/Resources/CustomerResource` |
| `ap.ecommerce.graphql.extend` | filter | `array $definition` (`types`, `query`, `mutation`, …), `TypeRegistry $registry` | `array` | `GraphQL/EcommerceSchema::build()` |

`{resource}` is the resource's `NAME` constant: `activityLogEntry`, `cart`,
`cartItem`, `coupon`, `customer`, `customerAddress`, `customerNote`,
`digitalDownload`, `digitalFile`, `inventoryItem`, `inventoryReservation`,
`kanbanAutomation`, `kanbanBoard`, `kanbanCard`, `kanbanCardWidget`,
`kanbanColumn`, `licenseActivation`, `licenseKey`, `notificationTemplate`,
`order`, `orderEdit`, `orderItem`, `orderNote`, `orderSubstatus`,
`orderTimelineEntry`, `product`, `productAttribute`, `productAttributeValue`,
`productCategory`, `productChild`, `productImage`, `productPrice`,
`productTag`, `promotion`, `promotionAction`, `promotionCondition`,
`promotionUsage`, `refund`, `refundItem`, `review`, `shipment`,
`shipmentItem`, `shippingMethod`, `shippingZone`, `taxClass`, `taxRate`,
`variant`, `webhookDelivery`, `webhookSubscription`.

The `publicMetaKeys` filters only run for non-admin requests; admin
requests see the whole `meta` object.

```php
addFilter( 'ap.ecommerce.api.resource.product', function ( array $data, Product $product, Request $request ): array {
    $data['loyalty_points'] = (int) ( $product->meta['loyalty_points'] ?? 0 );

    return $data;
} );
```

## Authorization

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.abilities.catalog` | filter | `array<string, array<string>> $catalog` (the engine's resources and their actions) | `array<string, array<string>>`. Non-string resources and actions are dropped. Satellites add their own resources here. | `Auth/AbilityCatalog` |
| `ap.ecommerce.abilities.{resource}.{action}` | filter | `bool $allowed` (the Gate decision), `Authenticatable $user` (never null — guests are refused before the filter runs), `Request $request`, `mixed $subject` (a model from policies/GraphQL; the request from REST middleware) | `bool` | `Auth/EcommerceAuthorizer::allows()` |

For example, `ap.ecommerce.abilities.order.refund`. See
[api-auth.md](api-auth.md#abilities) for how the decision is built.

## Planned, not yet fired

Of the hooks parent plan §6.3 sketched, these have no firing site in 1.0.0:
`product.updated` (use `product.saved`) and the filters `cart.tax` (use
`tax.calculating` / `tax.calculated`) and `cart.shipping_options` (use
`shipping.availableMethods`). They will use the `ap.ecommerce.*` scheme if
they land.
