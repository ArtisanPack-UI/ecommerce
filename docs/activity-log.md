# Activity log and customer notes

Orders keep their own append-only timeline (`order_timeline_entries`). Every
other entity an admin manages — products, customers, and promotions — gets
its history from one polymorphic, append-only table, `ecommerce_activity_log`,
so all three admin families can render the same "who changed what, when".

## How entries are written

| Source | What it records |
|---|---|
| `Listeners\RecordModelActivity` (Eloquent observer) | Creates, updates, and deletes of `Product`, `ProductVariant`, `ProductPrice`, `Customer`, `Promotion`, and `Coupon`. These writes have no engine service yet, so the observer is the one place every write passes through. |
| `Services\InventoryService::adjust()` | Every stock adjustment, with its delta and reason. |
| `Services\CustomerNoteService` | Adding and deleting customer notes. |
| `Services\ActivityLogService::record()` | Anything else — satellites can record their own event types against a product, customer, or promotion. |

Each entry is filed against the **owning top-level entity**: a variant or
price change against its product, an inventory adjustment against the
product that owns the stock row (directly or through a variant), a coupon
change against its promotion, a note against its customer.

The actor defaults to the signed-in user when their id is numeric, else
`null` (system). Pass `$actorUserId` to `record()` to set it explicitly.

Turn recording off with `ECOMMERCE_ACTIVITY_LOG_ENABLED=false`
(`artisanpack.ecommerce.activity_log.enabled`).

## Payloads

Created and deleted entries carry a short summary of the row. Updated
entries carry a diff:

```json
{ "changes": { "name": { "before": "Mug", "after": "Big mug" } } }
```

Timestamps and counters the engine maintains itself (`avg_rating`,
`reviews_count`, customer order totals, `times_used`) are left out of the
diff, and an update that only touched those is not recorded.

## Event types

`ActivityLogService::EVENT_TYPES` is the authoritative list.

| Event type | Subject | Payload |
|---|---|---|
| `product.created`, `product.deleted` | product | `name`, `sku`, `type`, `status` |
| `product.updated` | product | `changes` |
| `variant.created`, `variant.deleted` | product | `variant_id`, `sku`, `name` |
| `variant.updated` | product | `variant_id`, `sku`, `changes` |
| `price.created`, `price.deleted` | product | `price_id`, `variant_id` (null for a product price), `currency`, `amount`, `compare_at_amount` |
| `price.updated` | product | `price_id`, `variant_id`, `currency`, `changes` |
| `inventory.adjusted` | product | `inventory_item_id`, `variant_id`, `delta`, `quantity_on_hand` (`{before, after}`), `reason` |
| `customer.created`, `customer.deleted` | customer | `email`, `first_name`, `last_name` |
| `customer.updated` | customer | `changes` |
| `note.added`, `note.deleted` | customer | `note_id`, `excerpt` (first 120 characters) |
| `promotion.created`, `promotion.deleted` | promotion | `name`, `key` |
| `promotion.updated` | promotion | `changes` |
| `coupon.created`, `coupon.deleted` | promotion | `coupon_id`, `code` |
| `coupon.updated` | promotion | `coupon_id`, `code`, `changes` |

UIs should describe unknown event types generically (the type plus the raw
payload) so satellite events still render.

## Reading

```php
use ArtisanPackUI\Ecommerce\Services\ActivityLogService;

$entries = app( ActivityLogService::class )->forSubject( $product )->limit( 20 )->get();
```

Over REST: `GET admin/activity/products/{product}`,
`GET admin/activity/customers/{customer}`, and
`GET admin/activity/promotions/{promotion}`, each gated on the subject's
`view` ability, cursor-paginated, newest first, and filterable with
`filter[event_type]=…`.

## Customer notes

Internal staff notes on a customer, never shown to the shopper.

```php
use ArtisanPackUI\Ecommerce\Services\CustomerNoteService;

$notes = app( CustomerNoteService::class );

$note = $notes->add( $customer, 'Prefers phone contact', auth()->id() );
$notes->list( $customer );            // newest first
$notes->delete( $note, auth()->id() );
```

Over REST: `GET` / `POST customers/{customer}/notes` and
`DELETE customers/{customer}/notes/{note}` (`customer.view` /
`customer.update`; writes need an `Idempotency-Key`).

## Personal data

Customer entries hold the same personal data the admin already shows
(email, name, phone). The customer delete-and-anonymize flow must, in one transaction:

1. run its anonymizing writes inside `ActivityLogService::withoutRecording( fn () => … )`,
   so the `customer.updated` / `customer.deleted` entries they would produce
   (which carry the before values) are never written; then
2. call `ActivityLogService::scrubCustomer( $customer )` last, which deletes
   the customer's notes and replaces every personal-data value in that
   customer's existing entries with `[redacted]`.

Scrubbing first and anonymizing afterwards would write the erased values
back into new entries. `scrubCustomer()` is the only sanctioned mutation of
the append-only log. It does not cover order-side text (order notes, the
`note.added` timeline excerpts, cancel reasons); the anonymize flow owns those.

Price entries never include `cost_amount`.

## Hooks

See [hooks.md](hooks.md#activity-log).
