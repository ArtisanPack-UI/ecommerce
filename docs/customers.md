# Customers: addresses and deletion

Admin writes for customers that go beyond `PATCH customers/{customer}`
(engine issue #142). Admin UIs call the services in-process; the React and
Vue admins use the REST endpoints on top of them.

## Addresses

`Services\CustomerAddressService` creates, updates, and deletes a customer's
saved addresses.

```php
use ArtisanPackUI\Ecommerce\Services\CustomerAddressService;

$addresses = app( CustomerAddressService::class );

$address = $addresses->create( $customer, [
    'label'        => 'Home',
    'address1'     => '1 Main St',
    'city'         => 'Springfield',
    'postal_code'  => '12345',
    'country_code' => 'US',
], auth()->id() );

$addresses->update( $address, [ 'is_default_billing' => true ], auth()->id() );
$addresses->delete( $address, auth()->id() );
```

- Writable columns: `label`, `first_name`, `last_name`, `company`, `phone`,
  `address1`, `address2`, `city`, `region`, `region_code`, `postal_code`,
  `country_code`, `is_default_shipping`, `is_default_billing`. Anything else,
  including `customer_id`, is ignored.
- `address1`, `city`, and `country_code` are required. Text is trimmed, blanks
  become `null`, and country and region codes are upper-cased. `update()` only
  changes the keys you pass.
- The customer's first address becomes default shipping and billing. Setting
  either flag on an address clears it on the customer's other addresses.
  Deleting a default does not promote another address.
- Each write records `address.added`, `address.updated`, or `address.deleted`
  in the [activity log](activity-log.md) and fires the matching
  `ap.ecommerce.customer.address*` action once the outermost transaction
  commits ([hooks.md](hooks.md#customers)). Deleting an address that is
  already gone does nothing.

### Errors

A refused write throws `Exceptions\CustomerWriteException`, whose `errors`
hold `{ field, code, message }` entries (codes `required`, `too-long`,
`invalid-country`, `invalid`). Over REST it is a 422 `customer-write-failed`
problem.

### REST

| Method | Path | Ability | Idem. |
|---|---|---|---|
| `POST` | `customers/{customer}/addresses` | `customer.update` | yes |
| `PATCH` | `customers/{customer}/addresses/{address}` | `customer.update` | yes |
| `DELETE` | `customers/{customer}/addresses/{address}` | `customer.update` | yes |

The address must belong to the customer in the path; otherwise the response
is a 404.

## Deleting a customer

`CustomerService::delete( Customer $customer, ?int $actorUserId = null )`
handles GDPR erasure (parent plan §19.6). Everything happens in one
transaction with the customer row locked.

"The customer's orders" means orders linked by `customer_id` **and**
unlinked guest orders placed under the customer's email (matched
case-insensitively), so a later claim cannot re-attach them. Guest carts
and guest reviews under the same email are matched the same way.

| Data | What happens |
|---|---|
| The customer row | Deleted. |
| Orders | Kept, with every money column and line item, so revenue, tax, and refund reports do not change. `customer_id`, `phone`, `shipping_address`, `billing_address`, `ip_address`, `user_agent`, and `customer_note` are nulled; `email` becomes `CustomerService::ANONYMIZED_EMAIL`. |
| Order notes | Bodies become `[redacted]`. |
| Order timeline | `excerpt`, `reason`, and `reasons` values become `[redacted]` in every entry on those orders (note excerpts; status, edit, cancel, and refund reasons; fraud reasons). Amounts, statuses, and ids are kept. |
| Refunds | Kept; `reason` becomes `[redacted]`. |
| Order-edit history | `reason` becomes `[redacted]`, and the personal fields (`email`, `phone`, `customer_note`, addresses) in `pre_edit_snapshot` and `diff` are scrubbed, so rolling an edit back cannot restore them. |
| Download events, license activations | IP address and user agent are nulled. Download links and license keys keep working. |
| Outbound webhook deliveries | Rows that reference those orders or the customer keep their history, but personal values in `payload` are redacted, `payload_hash` is recomputed, and the stored `response_body` is dropped. |
| Stored idempotent responses | Records from the customer's own routes (`customers.update`, address and note writes) whose response names the customer are deleted. |
| Addresses, notification preferences, claim attempts | Deleted. |
| Customer notes, activity entries | Notes are deleted and personal values in the customer's activity entries become `[redacted]` (`ActivityLogService::scrubCustomer()`). A `customer.deleted` entry with counts only is recorded. |
| Carts | Kept (they may hold stock reservations); `customer_id` and `email` are nulled. |
| Reviews | Kept; `customer_id` and `author_email` are nulled and `author_name` becomes `Anonymous`. |
| Promotion usages | Kept for usage counts; `customer_id` is nulled. |
| The linked user account | Untouched. It belongs to the host application. |

Once the outermost transaction commits, `ap.ecommerce.customer.deleted`
fires with the deleted `Customer` (its attributes as they were, so
satellites can erase their own copies) and the summary counts. If an
enclosing transaction rolls back, nothing is deleted and the hook never
fires.

### Known retention

The delete does not reach these; erase or expire them by your own policy:

| Store | Why it is left |
|---|---|
| `inbound_webhook_deliveries.payload` / `parsed` | Raw payment-provider webhooks (they can include the shopper's email or address). They are keyed by provider event, not by order or customer, so they can't be matched reliably. Prune them on a schedule. |
| Idempotent responses from order routes (`orders.update`, refunds, shipments, cancel, notes) | They guard money-moving actions against a replayed request, so deleting them early could repeat a refund. They expire after `artisanpack.ecommerce.idempotency.default_ttl_hours` (24 h by default). |
| Order item snapshots (`order_items.product_snapshot`, `meta`) and `orders.meta` | Product data, plus whatever a satellite stored in `meta`. Satellites that put personal data there should erase it on `ap.ecommerce.customer.deleted`. |
| Payment gateway records, queued mail, application logs | Outside the engine's tables. |

Over REST: `DELETE customers/{customer}` with the `customer.delete` ability
and an `Idempotency-Key`. The response carries only
`{ "data": { "type": "customer", "id": …, "deleted": true } }`, never the
erased data.

There is no GraphQL mutation for customer writes; use REST.
