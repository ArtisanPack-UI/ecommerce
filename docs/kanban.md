# Kanban engine

Orders move across kanban boards (parent plan §9). The engine owns the data,
the rules, and one shared REST API. The Livewire, React, and Vue kanban
satellites all use that API, so none of them holds kanban logic of its own.

## Data model

| Table | Holds |
|---|---|
| `kanban_boards` | A board: `key`, `name`, `routing_rules`, `is_default`, `is_active`, `position`, `settings`. |
| `kanban_columns` | One column per order sub-status on a board, plus display overrides, a `wip_limit`, and the column's `card_widgets`. |
| `kanban_automations` | "When a card moves from column A (or any column) to column B and the conditions pass, run trigger X with this config." |
| `kanban_card_widgets` | Optional per-store overrides (label, default config) for registered widget types. |
| `order_board_assignments` | The cards. There is one row per (order, board). The row's `substatus_id` is the card's column, and `moved_at` records when the card entered that column. Removing a card sets `removed_at`; the row isn't deleted. |

An order can be on **any number of boards at once**, and each card has its own
column. A photography order can be at "Printing" on the Production board and
at "Awaiting label" on the Shipping board at the same time.

## Order sub-statuses

Every column is an order sub-status, and the same sub-statuses also refine an
order's own `system_status` (`orders.substatus_id`). Sub-statuses are
user-defined rows in `order_substatuses`, each under one of the six system
statuses (`pending`, `processing`, `complete`, `cancelled`, `refunded`,
`failed`). Migration `2026_01_01_000014` seeds one default per system status
(`awaiting-payment`, `in-progress`, `completed`, `cancelled`, `refunded`,
`failed`).

Manage them through `OrderSubstatusService`, `admin/order-substatuses`, or the
GraphQL `…OrderSubstatus` mutations, all gated by the `orderSubstatus`
abilities:

```php
$service = app( \ArtisanPackUI\Ecommerce\Services\OrderSubstatusService::class );

$printing = $service->create( [ 'system_status' => 'processing', 'label' => 'Printing', 'color' => '#a855f7' ] ); // key "printing"
$service->update( $printing, [ 'label' => 'On the press' ] );
$service->reorder( 'processing', [ $printing->id ] ); // listed ids first, the rest keep their order
$service->delete( $printing );
```

- `key` is a lowercase slug, unique within its system status. Leave it blank
  on create and it is derived from the label.
- `color` is `null` or `#RRGGBB` (stored upper-case); `icon` is a free icon
  name up to 80 characters; `is_terminal` marks a finished state for the
  multi-board roll-up.
- `system_status` can't change after creation, because that would strand the
  orders, cards, and columns on it. Create a new sub-status instead.
- Delete is refused while orders, active board cards, or kanban columns use the
  sub-status. The `OrderSubstatusWriteException` names the counts in its
  message and in `$exception->counts` (`orders`, `assignments`, `columns`).
  Removed cards that still point at it are deleted with it. The last
  sub-status of a system status can't be deleted.

Refusals throw `OrderSubstatusWriteException` with `{ field, code, message }`
errors (REST: 422 `substatus-write-failed`; the in-use error also carries
`counts`). A key left blank is made from the label, with `-2`, `-3`, … added
when a sibling already uses it. Reads go through the cached `SubStatusRegistry`
(`all()`, `forSystemStatus()`, `get( $idOrKey, $systemStatus )`; a digit-only
string is treated as an id). Service writes and model saves/deletes flush it,
again when the outermost transaction commits, and rows read inside a
transaction are not cached. Writes that bypass the model (raw `DB::table()`)
must call `SubStatusRegistry::flush()`; the demo seeder does. Each write fires an
`ap.ecommerce.orderSubstatus.*` action; see [hooks.md](hooks.md#orders).

## Routing

`KanbanRoutingService` decides which boards an order goes on. With
`artisanpack.ecommerce.kanban.auto_route` on (the default), it runs on
`ap.ecommerce.order.placed`. It runs again on `ap.ecommerce.order.edited`.

- A board **with** `routing_rules` catches every order that satisfies the rules.
- A board **without** rules catches every order.
- An `is_default` board without rules is the fallback. It only gets orders
  that no other board caught.
- The final list of boards runs through `ap.ecommerce.kanban.routingBoards`.

Each new card starts in the board's first column for the order's current
`system_status`. If the board has no such column, the card starts in the
board's first column in the forward chain (see [Status roll-up](#status-roll-up)).
If the board has no column that can hold the order at all, the board is
skipped and the skip is logged.

When routing runs again after an edit:

- Boards that now match get a card.
- Boards that have rules and no longer match lose their card.
- Boards without rules keep their cards, including cards added by hand.
- `ap.ecommerce.kanban.boardReassigned` fires with the added and removed board
  ids.

### Routing rules and automation conditions

Both use the same condition tree. The leaves are **promotion conditions**, so
any core or satellite `PromotionCondition` also works as a routing rule:

```json
{ "all": [ node, … ] }                      every child passes (an empty list passes)
{ "any": [ node, … ] }                      at least one child passes (an empty list fails)
{ "not": node }
{ "type": "min-subtotal", "config": { "amount": 5000 } }
[ node, … ]                                 shorthand for "all"
```

Examples:

```json
// Production: any order with a physical print on it
{ "type": "cart-contains-product-type", "config": { "types": [ "simple" ] } }

// Downloads: digital-only orders
{ "type": "cart-contains-product-type", "config": { "types": [ "digital" ], "match": "only" } }
```

A condition runs against an in-memory cart rebuilt from the order's lines. If
it implements `OrderAwarePromotionCondition`, it runs against the order itself
instead. `customer-first-order` does this, so it doesn't count the order being
routed. A condition fails closed when:

- its type isn't registered;
- its node is malformed;
- the tree is nested more than 10 levels deep;
- it throws.

## Moving cards

`KanbanBoardService::move( $order, $toColumn )` is the only way a card moves,
whether the move comes from a drag, an external event, or a rule. A move:

1. Runs `ap.ecommerce.kanban.cardMoving`. The filter can return `null` to veto
   the move or change `to_column_id` to redirect it.
2. Checks the target column's `wip_limit`.
3. Recalculates the order's status from all of its boards and transitions the
   order if that status changed (see below).
4. Updates the card. This fires `ap.ecommerce.order.substatusChanged` and
   `OrderSubstatusChanged`, with the board id.
5. After the change is committed, fires `ap.ecommerce.kanban.cardMoved` and
   dispatches `KanbanCardMoved`. The board's automations then run, and the
   move is broadcast.

### Status roll-up

Boards can be ahead of or behind the order anywhere in the forward chain
`pending → processing → complete`. The order's `system_status` is the most
advanced status across its boards, with one exception: `complete` requires
**every** board to be on a terminal `complete` column. If the Production board
finishes before the Shipping board, the order stays at `processing`.

Moving a card into an exit column (`cancelled`, `refunded`, `failed`) moves the
whole order to that status. The status machine can reject a transition. For
example, a single-board order can't go from `pending` straight to `complete`.
When that happens, the move is rolled back.

`ecommerce:audit-order-status` reports a card whose status is outside the
order's forward chain. A card that is only ahead of or behind the order within
the chain isn't reported.

## Automations

A move runs every active automation on the board where:

- `to_column_id` is the column the card entered;
- `from_column_id` is the column the card left, or is `null`;
- `conditions` pass for the order.

Each automation runs independently. An unknown trigger or a trigger that throws
is logged and recorded on the order timeline as `kanban.automation_failed`, and
the other automations still run. A trigger that succeeds records
`kanban.automation_fired`, fires `ap.ecommerce.kanban.automationFired`, and
dispatches `KanbanAutomationTriggered`.

Core triggers:

| Key | Config | Does |
|---|---|---|
| `send-email` | `to` (`"customer"`, an address, or a list), `subject`, `body` | Queues a mail. The subject and body accept `{order_number}`, `{order_id}`, `{email}`, `{status}`, `{board}`, and `{column}`. |
| `dispatch-job` | `job` | Queues `new $job( $orderId )`. The class must be listed in `artisanpack.ecommerce.kanban.dispatchable_jobs`. |
| `webhook` | `url`, optional `secret` | Queues a `POST` of the order. The URL must be `https` and resolve to a public host, as outbound webhooks must. The request is signed with `X-ArtisanPack-Signature` when a secret is set. |
| `update-order-field` | `field`, `value` | Sets `meta.<path>`, or a top-level column listed by `ap.ecommerce.kanban.updatableOrderFields`. Nothing is listed by default. |
| `create-shipment` | `method_key` (defaults to the order's), `carrier`, `service` | Ships every unshipped unit. |
| `print-shipping-label` | `provider`, `method_key` | Buys a label through a `ShippingLabelProvider` satellite. If there's no unlabelled shipment yet, it creates one first. |

To add a trigger from a satellite:

```php
app( KanbanAutomationRegistry::class )->register( 'slack:notify-slack', NotifySlack::class );
```

## Card widgets

Widgets are the small pieces of information on a card. Each widget returns a
framework-agnostic payload that every UI satellite renders its own way:

```json
{ "key": "total", "label": "Total", "value": "$124.00", "tone": "neutral",
  "icon": null, "tooltip": null, "href": null, "refresh_channel": null }
```

A card shows the widgets in its column's `card_widgets`. If that list is
empty, it uses the board's `settings.card_widgets`. If that's empty too, it
uses `artisanpack.ecommerce.kanban.default_card_widgets`.

Core widgets:

- `total`
- `item-count`
- `customer`
- `shipping-method`
- `tags` (reads `meta.tags`; filter with `ap.ecommerce.kanban.orderTags`)
- `days-in-column` (turns `warning` after `stale_after_days` and `danger` after
  twice that)
- `payment-status`
- `fulfillment-status`

A widget can return a `refreshSubscription()` channel for live updates. Every
payload runs through `ap.ecommerce.kanban.widgetRendered`. A widget that throws
is logged and left off the card.

## REST API

All routes are under `/api/ecommerce/v1/kanban` and are admin-only. Mutations
need an `Idempotency-Key`. Every route uses the `ecommerce.admin.mutate` rate
policy.

| Method | Path | Ability |
|---|---|---|
| GET | `boards` | `kanbanBoard.viewAny` |
| POST | `boards` | `kanbanBoard.create` |
| GET | `boards/{board}` (includes columns with `card_count` and `is_over_wip_limit`) | `kanbanBoard.view` |
| PATCH | `boards/{board}` | `kanbanBoard.update` |
| DELETE | `boards/{board}` | `kanbanBoard.delete` |
| GET | `boards/{board}/cards?filter[column_id]=…&cursor=…` | `kanbanBoard.view` |
| POST | `cards/{order}/move` with `{ to_column_id, reason? }` | `kanbanCard.move` |
| POST | `boards/{board}/columns` | `kanbanBoard.update` |
| PATCH | `columns/{column}` | `kanbanBoard.update` |
| DELETE | `columns/{column}` (only when the column is empty) | `kanbanBoard.update` |
| POST | `boards/{board}/automations` | `kanbanBoard.update` |
| PATCH | `automations/{automation}` | `kanbanBoard.update` |
| DELETE | `automations/{automation}` | `kanbanBoard.update` |
| POST | `boards/{board}/assignments/{order}` with `{ column_id? }` | `kanbanCard.move` |
| DELETE | `boards/{board}/assignments/{order}` | `kanbanCard.move` |
| GET | `widgets` | `kanbanBoard.viewAny` |
| GET | `triggers` | `kanbanBoard.viewAny` |

A refused move returns `422 problem+json`. The problem `type` ends in one of:

- `not-on-board`
- `wip-limit-reached`
- `move-rejected`
- `column-not-on-board`
- `invalid-status-transition`
- `incompatible-column`

An automation's `trigger_config.secret` is never returned in responses;
`has_secret` shows whether one is set. A `PATCH` that leaves out the secret
keeps the stored one.

## Real-time updates

Set `ECOMMERCE_KANBAN_BROADCAST=true` and configure a broadcaster. Every move
is then broadcast on `private-ecommerce.kanban.board.{board}`. Only users with
the `kanbanBoard.view` ability are authorized for the channel.

```js
Echo.private(`ecommerce.kanban.board.${boardId}`).listen('.kanbanCardMoved', ({ data }) => {
  const { card, from_column_id, to_column_id } = data.kanbanCardMoved;
});
```

The payload has the same shape as the `kanbanCardMoved` GraphQL subscription
field. `card` is the full `KanbanCard` resource, including its rendered
widgets.

## Contract tests

Satellite widgets extend `KanbanCardWidgetContractTest`, and satellite
triggers extend `KanbanAutomationTriggerContractTest`. See
[contracts.md](contracts.md).
