# Events reference

Every Laravel event class the engine dispatches, from `src/Events/`. Events
complement [hooks](hooks.md). Hooks are synchronous and can change data.
Events are the place for async side effects: emails, webhook delivery,
external syncs, and analytics (parent plan §6.4).

```php
use ArtisanPackUI\Ecommerce\Events\OrderRefunded;
use Illuminate\Support\Facades\Event;

Event::listen( OrderRefunded::class, function ( OrderRefunded $event ): void {
    Accounting::recordRefund( $event->order->order_number, $event->refund->amount );
} );
```

## Dispatch semantics

- **After commit.** Every class implements `ShouldDispatchAfterCommit`. An
  event dispatched inside a database transaction reaches its listeners only
  once the outermost transaction commits, and never if it rolls back.
  Outside a transaction it is delivered immediately.
- Events are dispatched with `Event::dispatch()`, usually right after the
  matching `doAction()` hook. The exceptions, with no paired hook, are
  `FraudChallenged`, `PaymentFailed` when a fraud block raises it,
  `CustomerRegistered` and `CustomerUpdated` (fired from the `Customer`
  model, so every write path raises them), and `CartCompleted`,
  `PromotionApplied`, and `CouponRedeemed` (they follow
  `ap.ecommerce.order.placed`).
- None of the event classes implement `ShouldQueue` or `ShouldBroadcast`.
  To do the work asynchronously, make **your listener** implement
  `ShouldQueue`.
- Every public property is `readonly` and set through the constructor.
- **Webhooks.** Each class listed in `artisanpack.ecommerce.webhooks.events`
  is delivered to outbound webhook subscribers by the `DispatchWebhooksForEvent`
  listener. Delivery itself runs on the queue (`DeliverWebhookJob`). The wire
  name is the snake-cased, dot-separated class name
  (`WebhookPayloadFactory::eventName()`), so `OrderStatusChanged` becomes
  `order.status.changed`. Any event class can be added to that config list.
  The list is read at boot. A host that published the config before 1.0.0
  must add the new classes to its copy. See [webhooks.md](webhooks.md#events).
- **Broadcasts.** When `artisanpack.ecommerce.graphql.subscriptions` is on,
  some events are re-broadcast as `GraphQLSubscriptionBroadcast` (which *is*
  `ShouldBroadcast`) on `private-ecommerce.admin`. When
  `artisanpack.ecommerce.kanban.broadcast` is on, `KanbanCardMoved` is
  re-broadcast on `private-ecommerce.kanban.board.{board}`. See
  [graphql.md](graphql.md) and [kanban.md](kanban.md).

All events are available since **1.0.0** and live in the
`ArtisanPackUI\Ecommerce\Events` namespace.

## Summary

| Event | Dispatched by | Webhook by default | Wire name | Broadcast as |
|---|---|---|---|---|
| `CartCreated` | `CartService::create()` | | `cart.created` | |
| `CartUpdated` | `CartService` (add, set quantity, remove, clear, token rotation), `StorefrontCartService` (customer attached, currency changed) | | `cart.updated` | |
| `CartMerged` | `CartMergeService` (guest cart merged at sign-in) | | `cart.merged` | |
| `CartAbandoned` | `ecommerce:flag-abandoned-carts` | yes | `cart.abandoned` | |
| `CartCompleted` | `OrderPlacementService` (cart converted to an order) | yes | `cart.completed` | |
| `OrderPlaced` | `OrderPlacementService` | yes | `order.placed` | |
| `PromotionApplied` | `OrderPlacementService` (once per applied promotion) | yes | `promotion.applied` | |
| `CouponRedeemed` | `OrderPlacementService` | yes | `coupon.redeemed` | |
| `OrderStatusChanged` | `OrderStatusMachine::transition()` | yes | `order.status.changed` | `orderStatusChanged` |
| `OrderSubstatusChanged` | `OrderStatusMachine::setSubstatus()` | yes | `order.substatus.changed` | |
| `OrderEdited` | `OrderEditService` | yes | `order.edited` | |
| `OrderCancelled` | `OrderCancellationService::cancel()` | yes | `order.cancelled` | |
| `OrderRefunded` | `RefundService` | yes | `order.refunded` | |
| `PaymentSucceeded` | `PaymentOrchestrator` (capture) | yes | `payment.succeeded` | `paymentSucceeded` |
| `PaymentFailed` | `PaymentOrchestrator` (gateway threw, capture declined or mismatched, or fraud block) | yes | `payment.failed` | |
| `PaymentRefunded` | `RefundService` | yes | `payment.refunded` | |
| `FraudBlocked` | `PaymentOrchestrator` | yes | `fraud.blocked` | |
| `FraudChallenged` | `PaymentOrchestrator` | | `fraud.challenged` | |
| `ShipmentCreated` | `ShipmentService::create()` | yes | `shipment.created` | |
| `ShipmentDelivered` | `ShipmentService` (created or tracked as delivered), `LocalPickupHandoff::redeem()` | yes | `shipment.delivered` | |
| `OrderFulfilled` | `ShipmentService::create()` (every line fulfilled) | yes | `order.fulfilled` | |
| `CustomerRegistered` | `Customer` model (`created`) | yes | `customer.registered` | |
| `CustomerUpdated` | `Customer` model (`updated`) | yes | `customer.updated` | |
| `ProductStockLow` | `InventoryService` (on-hand crossed the low-stock threshold) | yes | `product.stock.low` | |
| `ProductOutOfStock` | `InventoryService` (on-hand reached zero) | yes | `product.out.of.stock` | |
| `ReviewSubmitted` | `ReviewService::submit()` | | `review.submitted` | `reviewSubmitted` |
| `ReviewApproved` | `ReviewService::approve()` | | `review.approved` | |
| `DigitalDownloadTokenIssued` | `DigitalDownloadService` | | `digital.download.token.issued` | |
| `DigitalProductUpdated` | `DigitalFileService` (new file version) | | `digital.product.updated` | |
| `LicenseIssued` | `LicenseService` | | `license.issued` | |
| `LicenseActivated` | `LicenseService` | | `license.activated` | |
| `LicenseDeactivated` | `LicenseService::deactivate()` | | `license.deactivated` | |
| `LicenseRevoked` | `LicenseService` | | `license.revoked` | |
| `KanbanCardMoved` | `KanbanBoardService` | | `kanban.card.moved` | `kanbanCardMoved` (board channel) |
| `KanbanAutomationTriggered` | `KanbanAutomationRunner` | | `kanban.automation.triggered` | |
| `KanbanBoardAssignmentAdded` | `KanbanRoutingService` | | `kanban.board.assignment.added` | |
| `KanbanBoardAssignmentRemoved` | `KanbanRoutingService` | | `kanban.board.assignment.removed` | |
| `WebhookDelivered` | `WebhookDeliveryService` | | `webhook.delivered` | |
| `WebhookFailed` | `WebhookDeliveryService` | | `webhook.failed` | `webhookDeliveryFailed` |
| `WebhookSubscriptionDisabled` | `WebhookDeliveryService` | | `webhook.subscription.disabled` | |

"Webhook by default" means the class is in the shipped
`webhooks.events` list. The wire name column is what the event would be
called if you add it to that list.

## Cart

### `CartCreated`

```php
public function __construct( public readonly Cart $cart )
```

A new cart row was persisted.

### `CartUpdated`

```php
public function __construct( public readonly Cart $cart, public readonly array $changes = [] )
```

`$changes` describes the mutation:

- `[ 'item_id' => int, 'action' => 'added' | 'quantity_summed' | 'quantity_set' | 'removed' ]`
- `[ 'action' => 'cleared', 'reason' => string ]` (`converted` when the cart became an order)
- `[ 'action' => 'token_rotated' ]`
- `[ 'action' => 'customer_attached', 'customer_id' => int ]`
- `[ 'action' => 'currency_changed', 'currency' => string ]`

`$cart` is re-read after the change.

### `CartMerged`

```php
public function __construct( public readonly Cart $result, public readonly int $guestItemsMerged )
```

A guest cart was merged into a customer's cart at sign-in. Fires for every
merge resolution except `CancelMerge`, including merges that carried over no
lines (`guestItemsMerged` is then `0`).

### `CartAbandoned`

```php
public function __construct( public readonly Cart $cart )
```

`ecommerce:flag-abandoned-carts` flagged the cart: checkout started, an
email is known, no order was placed, and the cart has been untouched for
`cart.abandoned_after_minutes`. Each cart is flagged once (`abandoned_at`).
Fires after `ap.ecommerce.cart.abandoned`.

### `CartCompleted`

```php
public function __construct( public readonly Cart $cart, public readonly Order $order )
```

The cart was converted into `$order`. Fires after `OrderPlaced`.

## Orders

### `OrderPlaced`

```php
public function __construct( public readonly Order $order )
```

An order was placed from a cart through `OrderPlacementService`. Fires once
the placement commits, right after `ap.ecommerce.order.placed`.

### `PromotionApplied`

```php
public function __construct( public readonly Promotion $promotion, public readonly Cart $cart, public readonly Money $amount )
```

Fires once per promotion applied to a placed order, after `OrderPlaced`.
`$amount` is the discount that promotion granted.

### `CouponRedeemed`

```php
public function __construct( public readonly Coupon $coupon, public readonly Order $order, public readonly Money $amount )
```

A coupon code was redeemed by a placed order. `$amount` is the discount
granted by the coupon's promotion.

### `OrderStatusChanged`

```php
public function __construct( public readonly Order $order, public readonly string $from, public readonly string $to )
```

The order's `system_status` moved along the state machine (`pending →
processing → complete`, plus `cancelled`, `refunded`, and `failed`).

### `OrderSubstatusChanged`

```php
public function __construct(
    public readonly Order $order,
    public readonly ?OrderSubstatus $from,
    public readonly OrderSubstatus $to,
    public readonly ?int $boardId = null,
)
```

The order's sub-status changed, either globally (`$boardId === null`) or on a
kanban board.

### `OrderEdited`

```php
public function __construct( public readonly Order $order, public readonly array $diff, public readonly OrderEdit $edit )
```

A post-placement edit was applied. `$diff` is the structured before/after
change set and `$edit` is the persisted `order_edits` row.

### `OrderCancelled`

```php
public function __construct( public readonly Order $order, public readonly string $reason )
```

The order moved to `cancelled`. Its inventory reservations were released and a
payment that was still `pending` at the gateway was voided (`payment_status`
is now `voided`). Cancelling never refunds: when the order was paid, issue the
refund through `RefundService`.

`$reason` is the staff-entered text and is delivered to `order.cancelled`
webhook subscribers as-is. Don't put customer personal data in it.

### `OrderRefunded`

```php
public function __construct( public readonly Order $order, public readonly Refund $refund )
```

A refund (full or partial) was recorded against the order.

### `OrderFulfilled`

```php
public function __construct( public readonly Order $order )
```

A shipment fulfilled the order's last unfulfilled line. Fires after
`ap.ecommerce.order.fulfilled`.

## Shipping

### `ShipmentCreated`

```php
public function __construct( public readonly Shipment $shipment, public readonly Order $order )
```

Fires after `ap.ecommerce.shipping.shipmentCreated`.

### `ShipmentDelivered`

```php
public function __construct( public readonly Shipment $shipment, public readonly Order $order )
```

A shipment was created as delivered, a tracking update marked it
delivered, or a local-pickup code was redeemed. Fires after
`ap.ecommerce.order.delivered`.

## Customers

### `CustomerRegistered`

```php
public function __construct( public readonly Customer $customer )
```

A customer row was created, by any write path (the model's `created`
event), so it also covers rows that `ap.ecommerce.customer.registered`
doesn't see.

### `CustomerUpdated`

```php
public function __construct( public readonly Customer $customer, public readonly array $changes )
```

A customer row was updated. `$changes` lists the changed column names,
without the engine-maintained ones (`total_spent_amount`,
`total_spent_currency`, `orders_count`, `last_ordered_at`, `updated_at`,
`created_at`). An update that only touched those doesn't fire.

## Inventory

### `ProductStockLow`

```php
public function __construct( public readonly InventoryItem $item, public readonly int $onHand )
```

On-hand stock crossed the item's `low_stock_threshold`. Fires after
`ap.ecommerce.inventory.lowStock`.

### `ProductOutOfStock`

```php
public function __construct( public readonly InventoryItem $item )
```

On-hand stock dropped from above zero to zero or below. Fires after
`ap.ecommerce.inventory.outOfStock`.

## Payments and fraud

### `PaymentSucceeded`

```php
public function __construct( public readonly Order $order, public readonly PaymentResult $payment )
```

The gateway captured the payment. `orders.payment_status` is now `paid`.
Fires after `ap.ecommerce.payment.succeeded` and `ap.ecommerce.order.paid`.

### `PaymentFailed`

```php
public function __construct( public readonly ?Order $order, public readonly PaymentGateway $gateway, public readonly Throwable $reason )
```

Capture failed. The gateway threw, declined the capture, the captured amount or
currency didn't match the order, or the fraud provider blocked the order (the
authorization was voided).

### `PaymentRefunded`

```php
public function __construct( public readonly Order $order, public readonly RefundResult $result )
```

The gateway confirmed a refund. It fires alongside `OrderRefunded`.

### `FraudBlocked`

```php
public function __construct( public readonly Order $order, public readonly FraudDecision $decision )
```

The active fraud provider (or chain) returned `block`. The order is `failed`
and the decision is stored in `orders.meta.fraud_decision`. It is never shown
to the customer.

### `FraudChallenged`

```php
public function __construct( public readonly Cart $cart, public readonly FraudDecision $decision, public readonly PaymentSession $session )
```

The fraud provider asked for step-up verification (3DS). The authorization
stays open and the order stays `pending`.

## Reviews

### `ReviewSubmitted`

```php
public function __construct( public readonly ProductReview $review )
```

### `ReviewApproved`

```php
public function __construct( public readonly ProductReview $review )
```

Rejection and spam marking fire hooks only
(`ap.ecommerce.review.rejected`, `ap.ecommerce.review.markedSpam`).

## Digital delivery and licenses

### `DigitalDownloadTokenIssued`

```php
public function __construct( public readonly DigitalDownload $download, public readonly OrderItem $item )
```

### `DigitalProductUpdated`

```php
public function __construct( public readonly Product $product, public readonly DigitalFile $file )
```

A new file version was uploaded. Drives the "product updated" customer
notification.

### `LicenseIssued`

```php
public function __construct( public readonly LicenseKey $key, public readonly OrderItem $item )
```

### `LicenseActivated`

```php
public function __construct( public readonly LicenseActivation $activation )
```

### `LicenseDeactivated`

```php
public function __construct( public readonly LicenseKey $license, public readonly string $fingerprint )
```

An activation was released for the machine `$fingerprint`. Fires after
`ap.ecommerce.license.deactivated`.

### `LicenseRevoked`

```php
public function __construct( public readonly LicenseKey $key, public readonly ?string $reason )
```

## Kanban

### `KanbanCardMoved`

```php
public function __construct(
    public readonly Order $order,
    public readonly KanbanColumn $from,
    public readonly KanbanColumn $to,
    public readonly KanbanBoard $board,
)
```

The engine listens for this itself: `KanbanAutomationRunner` runs the board's
automations, and when broadcasting is enabled `BroadcastKanbanCardMoved`
re-broadcasts it.

### `KanbanAutomationTriggered`

```php
public function __construct( public readonly KanbanAutomation $automation, public readonly Order $order )
```

### `KanbanBoardAssignmentAdded` / `KanbanBoardAssignmentRemoved`

```php
public function __construct( public readonly OrderBoardAssignment $assignment )
```

Routing (or an admin) put an order on a board, or took it off one.

## Outbound webhooks

### `WebhookDelivered`

```php
public function __construct( public readonly WebhookDelivery $delivery )
```

### `WebhookFailed`

```php
public function __construct( public readonly WebhookDelivery $delivery, public readonly Throwable $reason )
```

A single attempt failed. The delivery is rescheduled per
`webhooks.backoff_seconds` until `webhooks.max_attempts` is reached.

### `WebhookSubscriptionDisabled`

```php
public function __construct( public readonly WebhookSubscription $subscription )
```

The subscription reached `webhooks.disable_after_failures` consecutive
failures and was switched off.

## Planned, not yet dispatched

Parent plan §6.4 also lists `ProductCreated` and `ProductUpdated`. They have
no class in 1.0.0. Listen to the `ap.ecommerce.product.saved`,
`ap.ecommerce.product.published`, and `ap.ecommerce.product.unpublished`
[hooks](hooks.md#products-and-search) instead.
