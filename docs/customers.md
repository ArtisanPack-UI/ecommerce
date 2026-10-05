# Customers

Customer records, the shopper's own account (engine issue #173), guest order
access (#175), and the admin writes that go beyond
`PATCH customers/{customer}` (#142): addresses and deletion. Storefronts and
admin UIs call the services in-process; the React and Vue front ends use the
REST endpoints on top of them.

## Customer records and users

A customer row exists per email (stored lowercased) and links to at most one
host user through `user_id`. `CustomerService::customerForUser( $user, $create )`
finds the signed-in user's customer and, with `$create`, makes one:

- a user who has verified their email (`MustVerifyEmail`) is linked through
  `linkUser()`, which claims an existing guest customer with that email;
- an unverified user only gets a new customer when no customer uses that
  email yet, so an unverified address never takes over someone else's guest
  orders.

`LinkCustomerOnUserVerified` links the guest customer as soon as a user
verifies their email. Every new customer fires `CustomerRegistered`
(webhook `customer.registered`) and every profile change fires
`CustomerUpdated` with the changed field names (`customer.updated`); changes
to the engine-maintained stats (`orders_count`, `total_spent_*`,
`last_ordered_at`) don't count as profile changes. See
[events](./events.md).

## The shopper's own account

Signed-in shoppers manage their own data under `me/*`. Every route needs a
token that allows storefront access; the customer is found (or created) with
`customerForUser( $user, true )`, and another customer's order or address is
a 404.

| Method | Path | What it does |
|---|---|---|
| `GET` / `PATCH` | `me` | Read or update the profile: `first_name`, `last_name`, `phone`, `locale`, `accepts_marketing` (`CustomerService::updateProfile()`). |
| `GET` / `POST` | `me/addresses` | List or save addresses. |
| `PATCH` / `DELETE` | `me/addresses/{address}` | Update or delete one of them. |
| `GET` | `me/orders` | Order history, newest first. `filter[status]` takes `open`, `completed`, `cancelled`, or a system status; includes `items`, `shipments`, `refunds`, `customer_notes`. |
| `GET` | `me/orders/{order}` | One order. |
| `POST` | `me/claims` | Claim the guest orders placed under my email, verified by one order number and its shipping postal code. Rate-limited per shopper (`customers.claim_rate_limit` per `claim_rate_window_minutes`) and per IP. |
| `GET` | `me/downloads`, `me/license-keys` | Digital entitlements and license keys ([digital delivery](./digital-delivery.md#from-the-account-area)). |
| `GET` | `me/account-menu` | The account navigation (below). |
| `GET` / `PATCH` | `me/notification-preferences` | [Notification preferences](./notifications.md#customer-preferences). |

`locale` sets the language the customer's notifications are sent in; a
customer without one takes the language of their first order. See
[localization](./localization.md#the-shoppers-language).

### Carts on sign-in

`Services\CurrentCart` resolves the shopper's cart: their account cart when
signed in, else the guest cart whose token is in the shared cookie
(`cart.cookie`, written by `Support\GuestCartCookie`). When a shopper signs
in, `MergeGuestCartOnLogin` merges the guest cart into the account cart
(`cart.merge_on_login`, on by default): both carts are locked, quantities are
capped by stock, and totals are refreshed. A guest cart in a different
currency isn't merged; it's left pending for the storefront to resolve with
`POST carts/{cart}/merge` (409 `cart-currency-mismatch` until a currency is
chosen) or the GraphQL `mergeCart` mutation. An account cart is only
accessible with its owner's session or token.

### Account menu

`Registries\AccountMenuRegistry` holds the account navigation every
storefront renders, mirroring the admin menu. The core entries are:

| Key | Label | Route name | Shown |
|---|---|---|---|
| `profile` | Account details | `ecommerce.account.profile` | always |
| `orders` | Orders | `ecommerce.account.orders` | always |
| `addresses` | Addresses | `ecommerce.account.addresses` | always |
| `downloads` | Downloads | `ecommerce.account.downloads` | when the customer has downloads |
| `license-keys` | License keys | `ecommerce.account.license-keys` | when the customer has license keys |
| `notifications` | Email preferences | `ecommerce.account.notifications` | always |

Storefronts define those route names; an entry whose route doesn't exist has
a `null` `url` (a full URL is passed through). Satellites add their own:

```php
app( AccountMenuRegistry::class )->register( 'wishlist', [
    'label'     => static fn (): string => __( 'Wishlist' ),
    'route'     => 'wishlist.index',
    'icon'      => 'heart',
    'position'  => 25,                                   // default 100
    'visible'   => static fn ( ?Customer $customer ): bool => null !== $customer,
    'satellite' => 'acme/ecommerce-wishlist',            // hidden once uninstalled
] );
```

`visibleTo( ?Customer )` returns the visible entries in position order,
through the `ap.ecommerce.accountMenu.entries` filter. Registering a key
twice throws in `local` and `testing` and logs a warning elsewhere.

## Guest orders

Guests can't sign in, so they reach their orders two ways:

- **Lookup by email and order number:**
  `GuestOrderLookupService::find( $email, $orderNumber, $ip )`, over REST as
  `GET orders/guest-lookup?email=&order_number=` and in GraphQL as
  `guestOrderLookup`. The email comparison is case-insensitive and
  constant-time, and "no such order" answers exactly like "wrong email" (404
  `order-not-found`). Failed lookups lock out the order number and the
  caller's IP for `checkout.guest_lookup.lockout_minutes` (default 15) after
  `max_failures_per_order` (5) or `max_failures_per_ip` (20) failures (429
  `lookup-locked`, with `Retry-After`); every request also counts against
  `rate_limits.lookup.attempt.per_ip` (30 a minute).
- **A signed link:** `Support\OrderViewToken::for( $order, ?$expires )` makes
  an expiring `{id}-{expiry}-{HMAC}` token (keyed by the app key, valid
  `checkout.order_view_ttl_days`, default 90). `OrderViewToken::url()` points
  at `checkout.order_view_url` with `{token}` replaced, or at the REST
  `GET order-views/{token}` endpoint (GraphQL: `orderByViewToken`). Guest
  order confirmations carry it as `Order.view_url`. Tampered or expired
  tokens, and anonymized orders, answer 404.

Both answer the order with `include=items,shipments` allowed, and send
`Cache-Control: private, no-store`.

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
| Outbound webhook deliveries | Found through the indexed `webhook_deliveries.order_id` / `customer_id` columns, which the dispatcher fills from each payload's subject (`WebhookDispatcher::subjectIds()`). The rows keep their history, but personal values in `payload` are redacted, `payload_hash` is recomputed, and the stored `response_body` is dropped. |
| Inbound provider webhooks | Rows about those orders' payments (matched on `session_reference`) or containing the customer's email lose their stored `payload` and `parsed` body. The rows, hashes, and sizes stay for the audit trail. |
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
| Inbound webhooks the delete can't match | A provider event that names neither the order's payment session nor the customer's email. `ecommerce:prune-ledgers` deletes inbound webhooks after `retention.inbound_webhooks_days` (90 by default). |
| Idempotent responses from order routes (`orders.update`, refunds, shipments, cancel, notes) | They guard money-moving actions against a replayed request, so deleting them early could repeat a refund. They expire after `artisanpack.ecommerce.idempotency.default_ttl_hours` (24 h by default). |
| Outbound webhook deliveries whose payload names neither an order nor a customer | E.g. a review event when `webhooks.include_admin_fields` is off, or a guest cart event. Their `order_id` / `customer_id` are null, so they can't be matched. `ecommerce:prune-ledgers` deletes finished deliveries after `retention.webhook_deliveries_days` (90 by default). |
| Order item snapshots (`order_items.product_snapshot`, `meta`) and `orders.meta` | Product data, plus whatever a satellite stored in `meta`. Satellites that put personal data there should erase it on `ap.ecommerce.customer.deleted`. |
| Payment gateway records, queued mail, application logs | Outside the engine's tables. |

Over REST: `DELETE customers/{customer}` with the `customer.delete` ability
and an `Idempotency-Key`. A token or signed service needs the dedicated
`ecommerce:customers.delete` scope (or `ecommerce:admin`);
`ecommerce:customers.write` isn't enough. The response carries only
`{ "data": { "type": "customer", "id": …, "deleted": true } }`, never the
erased data.

There is no GraphQL mutation for customer writes; use REST.
