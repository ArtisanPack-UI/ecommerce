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

## Pricing and promotions

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
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

## Payments and fraud

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.fraud.assessing` | filter | `array $context` (`shipping`, `provider_key`, `session_ref`), `Cart $cart` | Currently **observe-only**: the return value is not used. | `Services/PaymentOrchestrator` |
| `ap.ecommerce.fraud.assessed` | filter | `FraudDecision $decision`, `Cart $cart`, `PaymentSession $session` | `FraudDecision`. Non-`FraudDecision` returns are ignored. | `Services/PaymentOrchestrator` |
| `ap.ecommerce.fraud.blocked` | action | `Order $order`, `FraudDecision $decision` | | `Services/PaymentOrchestrator` |
| `ap.ecommerce.payment.charging` | action | `PaymentGateway $gateway`, `Money $amount`, `Order $order` | | `Services/PaymentOrchestrator` (before capture) |
| `ap.ecommerce.payment.succeeded` | action | `PaymentResult $result`, `Order $order` | | `Services/PaymentOrchestrator` |
| `ap.ecommerce.payment.failed` | action | `PaymentGateway $gateway`, `Throwable $reason`, `Order $order` | | `Services/PaymentOrchestrator` (declined capture) |
| `ap.ecommerce.payment.refunding` | filter | `Money $amount`, `Order $order`, `?string $reason` | `Money`. The currency and remaining-refundable guards run again on the result. | `Services/RefundService` |
| `ap.ecommerce.payment.refunded` | action | `RefundResult $result`, `Order $order` | | `Services/RefundService` |
| `ap.ecommerce.webhook_received` | action | `string $provider`, `WebhookResult $result`, `Request $request`. Fires once per verified, non-duplicate inbound event. | | `Http/Controllers/WebhookController` |
| `ap.ecommerce.gateway.{provider}.webhook_received` | action | `WebhookResult $result`, `Request $request` | | `Http/Controllers/WebhookController` |

## Orders

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.order.placed` | action | `Order $order` | | **Not fired by the engine yet.** See the note below. |
| `ap.ecommerce.order.statusChanged` | action | `Order $order`, `string $from`, `string $to` | | `Services/OrderStatusMachine::transition()` |
| `ap.ecommerce.order.canTransitionSubstatus` | filter | `bool $allowed`, `Order $order`, `?OrderSubstatus $from`, `OrderSubstatus $to`, `?int $boardId` | `bool`. `false` throws `SubstatusTransitionRejectedException`. | `Services/OrderStatusMachine::setSubstatus()` |
| `ap.ecommerce.order.substatusChanged` | action | `Order $order`, `?OrderSubstatus $from`, `OrderSubstatus $to`, `?int $boardId` | | `Services/OrderStatusMachine::setSubstatus()` |
| `ap.ecommerce.order.statusAuditDrift` | action | `array $row`: one drift finding (`order_id`, `order_system_status`, `substatus_id`, `substatus_system_status`, …) | | `Console/Commands/AuditOrderStatusCommand` |
| `ap.ecommerce.order.editing` | filter | `array $edit` (the requested edit), `Order $order` (locked) | `array` | `Services/OrderEditService` |
| `ap.ecommerce.order.recomputingTotals` | filter | `Order $order` | `Order` (mutate in place, or return an instance with the same key) | `Services/OrderEditService` |
| `ap.ecommerce.order.edited` | action | `Order $order`, `array $diff`, `OrderEdit $edit` | | `Services/OrderEditService` |
| `ap.ecommerce.order.shipped` | action | `Order $order`, `Shipment $shipment` | | `Services/ShipmentService` |
| `ap.ecommerce.order.itemFulfilled` | action | `Order $order`, `OrderItem $item` (once per line the shipment completes) | | `Services/ShipmentService` |
| `ap.ecommerce.order.fulfilled` | action | `Order $order` (every line fulfilled) | | `Services/ShipmentService` |
| `ap.ecommerce.order.delivered` | action | `Order $order`, `Shipment $shipment` | | `Services/ShipmentService`, `Shipping/LocalPickupHandoff` |
| `ap.ecommerce.order.refunded` | action | `Order $order`, `Refund $refund` | | `Services/RefundService` |
| `ap.ecommerce.order.cancelling` | action | `Order $order`, `string $reason` (before the void, the reservation release, and the status change) | | `Services/OrderCancellationService` |
| `ap.ecommerce.order.cancelled` | action | `Order $order` (cancelled) | | `Services/OrderCancellationService` |
| `ap.ecommerce.order.noteAdded` | action | `Order $order`, `OrderNote $note` | | `Services/OrderNoteService` |
| `ap.ecommerce.order.noteDeleted` | action | `OrderNote $note` (already deleted) | | `Services/OrderNoteService` |

> **`ap.ecommerce.order.placed`.** The engine *listens* for this action to
> send the order-confirmation notification, route the order onto kanban
> boards, and so on. It does not *fire* it yet, because the engine ships no
> checkout/order-placement service at 1.0.0. Whatever code creates the order
> (a storefront satellite, or your own checkout) must call
> `doAction( 'ap.ecommerce.order.placed', $order )` once the order and its
> lines are persisted. See the [README quick-start](../README.md#quick-start-from-composer-require-to-a-first-order).

## Customers

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.customer.registered` | action | `Customer $customer` (a new customer row was created) | | `Services/CustomerService` |
| `ap.ecommerce.customer.userLinked` | action | `Customer $customer`, `Authenticatable $user` | | `Services/CustomerService` |
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
| `ap.ecommerce.product.listQuery` | filter | `Builder $query`, `array $filters` | `Builder` | Not fired by the engine; admin list screens apply it to their product query so satellites can add filters. |
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
| `ap.ecommerce.license.issued` | action | `LicenseKey $key`, `OrderItem $item` | | `Services/LicenseService` |
| `ap.ecommerce.license.activated` | action | `LicenseActivation $activation` | | `Services/LicenseService` |
| `ap.ecommerce.license.revoked` | action | `LicenseKey $key`, `?string $reason` | | `Services/LicenseService` |
| `ap.ecommerce.license.validating` | filter | `array $response` (`valid`, `expires_at`, `product`, `revoked`, `reason`), `string $key`, `string $fingerprint` | `array` (the `POST /license/validate` body) | `Services/LicenseService` |

## Kanban

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.kanban.cardMoving` | filter | `array $move` (`order_id`, `board_id`, `from_column_id`, `to_column_id`, `actor_user_id`, `reason`), `OrderBoardAssignment $assignment` | `array`. A missing or non-numeric `to_column_id` rejects the move (`KanbanOperationException`). Changing it redirects the move. | `Services/KanbanBoardService` |
| `ap.ecommerce.kanban.cardMoved` | action | `Order $order`, `KanbanColumn $from`, `KanbanColumn $to`, `KanbanBoard $board` | | `Services/KanbanBoardService` |
| `ap.ecommerce.kanban.routingBoards` | filter | `array<int> $boardIds` (boards whose routing rules match), `Order $order` | `array<int>`. Non-numeric and inactive ids are dropped. | `Services/KanbanRoutingService` |
| `ap.ecommerce.kanban.boardAssignmentAdded` | action | `OrderBoardAssignment $assignment` | | `Services/KanbanRoutingService` |
| `ap.ecommerce.kanban.boardAssignmentRemoved` | action | `OrderBoardAssignment $assignment` | | `Services/KanbanRoutingService` |
| `ap.ecommerce.kanban.boardReassigned` | action | `Order $order`, `array<int> $addedBoardIds`, `array<int> $removedBoardIds` | | `Services/KanbanRoutingService::reroute()` |
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

## API augmentation

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.api.resource.{resource}` | filter | `array $data`, `Model $model`, `Request $request` | `array` | `Http/Resources/EcommerceResource::toArray()`. Applies to REST, GraphQL, and webhook payloads. |
| `ap.ecommerce.api.list.{resource}` | filter | `array $items` (each already serialized), `Builder $query`, `Request $request` | `array` | `Http/Controllers/Api/V1/ApiController` (list endpoints), `SearchController`, `KanbanCardController` |
| `ap.ecommerce.graphql.extend` | filter | `array $definition` (`types`, `query`, `mutation`, …), `TypeRegistry $registry` | `array` | `GraphQL/EcommerceSchema::build()` |

`{resource}` is the resource's `NAME` constant: `cart`, `cartItem`, `coupon`,
`customer`, `customerAddress`, `digitalDownload`, `digitalFile`,
`inventoryItem`, `inventoryReservation`, `kanbanAutomation`, `kanbanBoard`,
`kanbanCard`, `kanbanCardWidget`, `kanbanColumn`, `licenseActivation`,
`licenseKey`, `notificationTemplate`, `order`, `orderEdit`, `orderItem`,
`orderNote`, `orderTimelineEntry`, `product`, `productAttribute`,
`productAttributeValue`, `productPrice`, `promotion`, `promotionAction`,
`promotionCondition`, `promotionUsage`, `refund`, `refundItem`, `review`,
`shipment`, `shipmentItem`, `shippingMethod`, `shippingZone`, `taxClass`,
`taxRate`, `variant`, `webhookDelivery`, `webhookSubscription`.

```php
addFilter( 'ap.ecommerce.api.resource.product', function ( array $data, Product $product, Request $request ): array {
    $data['loyalty_points'] = (int) ( $product->meta['loyalty_points'] ?? 0 );

    return $data;
} );
```

## Authorization

| Hook | Type | Arguments | Returns | Fired in |
|---|---|---|---|---|
| `ap.ecommerce.abilities.{resource}.{action}` | filter | `bool $allowed` (the Gate decision), `Authenticatable $user` (never null — guests are refused before the filter runs), `Request $request`, `mixed $subject` (a model from policies/GraphQL; the request from REST middleware) | `bool` | `Auth/EcommerceAuthorizer::allows()` |

For example, `ap.ecommerce.abilities.order.refund`. See
[api-auth.md](api-auth.md#abilities) for how the decision is built.

## Planned, not yet fired

Parent plan §6.3 sketched these hooks. They have no firing site in 1.0.0:
`order.placed` (listened to but not fired; see above), `cart.abandoned`,
`checkout.started`, `checkout.address.captured`, `checkout.payment.initiated`,
`product.published`, `product.updated`, and the filters `price.display`,
`cart.subtotal`, `cart.shipping_options`, `cart.tax`, `cart.total`,
`checkout.available_gateways`, `order.number`, and `product.list.query`.
They will use the `ap.ecommerce.*` scheme when they land.
