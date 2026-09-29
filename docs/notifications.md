# Notifications

The engine sends a catalog of notifications (parent plan §14). Store owners
edit the copy themselves. They never touch Blade files, and they can't run PHP
from a template. Customers choose which kinds of notification they receive.

## Catalog

| Key | Sent when | To | Category |
|---|---|---|---|
| `order.confirmation.customer` | `ap.ecommerce.order.placed` (fired by the checkout service, which hasn't landed yet) | customer | transactional |
| `order.paid.admin` | `ap.ecommerce.payment.succeeded` | staff | transactional |
| `order.shipped.customer` | `ap.ecommerce.order.shipped` | customer | shipping-updates |
| `order.delivered.customer` | `ap.ecommerce.order.delivered`, once every shipment on the order is delivered | customer | shipping-updates |
| `order.cancelled.customer` | `ap.ecommerce.order.statusChanged` → `cancelled` | customer | transactional |
| `order.refunded.customer` | `ap.ecommerce.order.refunded` | customer | transactional |
| `review.request.customer` | `review_request_delay_days` after delivery | customer | review-requests |
| `digital.download-ready.customer` | downloads / license keys issued for a paid order | customer | transactional |
| `digital.product-updated.customer` | a digital file's `version` changes | buyers | transactional |
| `license.activated.customer` | a license key is activated on a new machine | customer | transactional |
| `inventory.low-stock.admin` | `ap.ecommerce.inventory.lowStock` | staff | transactional |
| `inventory.out-of-stock.admin` | `ap.ecommerce.inventory.outOfStock` | staff | transactional |
| `review.awaiting-moderation.admin` | a review is submitted and still pending | staff | transactional |

Customer notifications go to the order's customer record. For a guest order
they go to the order's email address. Staff notifications go to
`artisanpack.ecommerce.notifications.admin_emails` (the
`ECOMMERCE_NOTIFICATIONS_ADMIN_EMAILS` env var, comma-separated). If no staff
address is configured, staff notifications aren't sent. Notifications are
queued (`ShouldQueue`), so run a queue worker. Delayed sends such as the
review request need one. Queued payloads are encrypted, because the context
can carry download links and license keys. When a queued notification is
delivered, the template's `is_active` switch and the customer's preferences
are checked again, so opting out during a review request's delay still
stops it.

Satellites add catalog entries by registering a `NotificationTemplate`
(engine spec §4.14) with `NotificationTemplateRegistry`, then sending with
`NotificationDispatcher::send()`. The `CatalogNotificationTemplate` class
builds a definition from plain values, so you don't need to write a class:

```php
app( NotificationTemplateRegistry::class )->register( 'subscriptions.renewal-reminder.customer', new CatalogNotificationTemplate(
    'subscriptions.renewal-reminder.customer',
    __( 'Renewal reminder' ),
    'transactional',
    [ 'Store.name', 'Subscription.renews_at', 'Customer.first_name' ],
    [ 'Store' => [ … ], 'Subscription' => [ … ], 'Customer' => [ … ] ],
    'Your subscription renews soon',
    '<p>Hi {{ Customer.first_name }}, …</p>',
) );

app( NotificationDispatcher::class )->send( 'subscriptions.renewal-reminder.customer', $customer, [ 'Subscription' => [ … ], 'Customer' => [ … ] ], $subscription );
```

## Editable templates

Each catalog entry has a row in `notification_templates` for each channel
and locale. The editor API edits existing rows. Only default-locale rows are
seeded, so rows for other locales come from your own seeder or a satellite. The row holds the `subject` and `body` as **Twig** source, the
declared `variables`, `preview_data`, and `is_active`. Rows in the default
locale (`notifications.default_locale`, falling back to
`app.fallback_locale`) are seeded from the catalog the first time the
templates are listed. Seeding keeps any edits already made. It also refreshes
each row's declared variables from the catalog definition. At send time the
engine uses the row for the notification's locale, then the default-locale
row, then the catalog default. Rows with `is_active` set to false aren't sent.

Templates see plain data, never models. Variables are nested arrays under a
few roots: `Store`, `Order`, `Customer`, `Shipment`, `Refund`, `Review`,
`Product`, `Downloads`, `Licenses`, `License`, `Activation`, `File`, and
`InventoryItem`. Money arrives formatted (`$50.36`) and dates arrive as
ISO 8601 strings, so use the `date` filter to format them. For example:

```twig
Hi {{ Order.customer.first_name|default(Order.customer.name) }},
{% for item in Order.items %}{{ item.name }} × {{ item.quantity }} — {{ item.total }}{% endfor %}
```

### The sandbox

Templates render with `twig/twig`'s `SecurityPolicy` sandbox turned on for
every template:

- **Tags:** `if`, `for`, `apply`. Nothing else, so no `include`, `import`,
  `macro`, `embed`, `extends`, or `set`. Repeating `{% set s = s ~ s %}`
  doubles a string until PHP runs out of memory.
- **Filters:** `abs`, `capitalize`, `date`, `default`, `e` / `escape`,
  `first`, `join`, `keys`, `last`, `length`, `lower`, `merge`, `nl2br`,
  `number_format`, `replace`, `round`, `slice`, `striptags`, `title`, `trim`,
  `upper`, `url_encode`. Filters that take a callable (`map`, `filter`,
  `reduce`, `sort`) are excluded, and so is `raw`. So are filters that can
  build huge values from a short source: `batch` (its fill argument pads to
  any size), `format` (sprintf widths), and `split` (turns a literal into a
  list to loop over).
- **Functions:** `min`, `max`.
- **No methods or properties** on any object. The context is arrays only.
- **No `..` range operator.** It would let a template build enormous arrays.
- **Loops nest at most two deep.** This keeps loops over literal lists
  bounded by the source length.

Mail bodies are HTML and autoescaped. A customer named `<script>` renders as
text. Subjects and non-mail bodies are plain text. A source like
`{{ system('rm -rf /') }}` fails to compile, and the save is rejected.

When a template is saved, the engine checks it: both sources must compile
under the sandbox and render against sample data. They may also only
reference **declared variables**. A declared variable's parents are allowed
(`Order.customer` when `Order.customer.name` is declared), and so are the
template's own `for` loop variables. Errors come back one per problem, with
the line number.

### Hooks

| Hook | Use |
|---|---|
| `ap.ecommerce.notification.templateVariables` | Filters the render context `(array $vars, string $templateKey, mixed $subject)`. Use it to add your own values. Remember to declare them in your own templates. |
| `ap.ecommerce.notification.rendering` | Filters the body source before rendering `(string $body, string $templateKey, array $vars)`, e.g. to wrap every mail in a store layout. |
| `ap.ecommerce.notification.sending` / `.sent` | Fire around each delivery `(EcommerceNotification $notification, mixed $notifiable, string $channel)`. |

## Editor API

| Method | Path | Ability |
|---|---|---|
| GET | `admin/notification-templates` | `ecommerce.notificationTemplate.viewAny`. Filters: `key`, `channel`, `locale`, `is_active`. |
| GET | `admin/notification-templates/{template}` | `ecommerce.notificationTemplate.view` |
| PATCH | `admin/notification-templates/{template}` | `ecommerce.notificationTemplate.update`. Body: `subject`, `body`, `is_active`, `preview_data`. |
| POST | `admin/notification-templates/{template}/preview` | `ecommerce.notificationTemplate.update`, because it renders arbitrary sources. No Idempotency-Key: it saves nothing. |

Each `notificationTemplate` resource includes the catalog `label` and
`category`, the declared `variables` for editor autocomplete (`*` marks a
list, as in `Order.items.*.name`), and `preview_data`.

The preview endpoint renders the saved sources, or unsaved `subject` / `body`
sent in the request. It renders against `preview_data` merged over the
catalog's sample values. `Store` always holds the live store details. The
preview goes through **the same renderer and sandbox as delivery**, so what
the editor shows is what the customer receives. It also validates the sources
the same way a save does. Call it on each keystroke for a live preview.

```http
POST /api/ecommerce/v1/admin/notification-templates/7/preview
{ "body": "<p>Hi {{ Order.customer.first_name }}</p>", "preview_data": { "Order": { "customer": { "first_name": "Grace" } } } }
→ { "data": { "subject": "Your Acme order K7QM2XW9", "body": "<p>Hi Grace</p>" } }
```

Invalid sources return 422 `invalid-notification-template`, with `errors[]`
entries shaped `{ field, code, message }`. The possible codes are
`template-error`, `forbidden`, `forbidden-operator`, and
`undeclared-variable`.

GraphQL has the same operations: the `notificationTemplates` and
`notificationTemplate(id:)` queries, and the `updateNotificationTemplate` and
`previewNotificationTemplate` mutations (the latter returns
`rendered { subject body }`).

## Customer preferences

`customer_notification_preferences` stores one on/off switch for each
customer, channel, and category. The categories are `transactional`,
`shipping-updates`, `review-requests`, `marketing`, `abandoned-cart`, and
`back-in-stock`.

- A category with no row is on, except `marketing`, which follows the
  customer's `accepts_marketing` consent.
- **Transactional notifications always send.** An opt-out row is stored for
  the audit trail and never consulted.

| Method | Path | Access |
|---|---|---|
| GET | `me/notification-preferences` | Signed-in shopper with a storefront-capable token. Lists every channel × category with `is_enabled` and `is_locked`. |
| PATCH | `me/notification-preferences` | Same access, plus an Idempotency-Key. Body: `{ preferences: [ { channel, category, is_enabled } ] }`. |

Preference channels come from `notifications.preference_channels` (default
`[ 'mail' ]`).
