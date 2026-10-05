# Notifications

The engine sends a catalog of notifications (parent plan §14). Store owners
edit the copy themselves. They never touch Blade files, and they can't run PHP
from a template. Customers choose which kinds of notification they receive.

## Catalog

| Key | Sent when | To | Category |
|---|---|---|---|
| `order.confirmation.customer` | `ap.ecommerce.order.placed` (fired when `OrderPlacementService` places an order) | customer | transactional |
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
builds a definition from plain values, so you don't need to write a class.
The label, subject, and body may be strings or closures that take the locale;
use closures so the copy is translated when it's sent, in the recipient's
language, rather than once when the catalog is registered:

```php
app( NotificationTemplateRegistry::class )->register( 'subscriptions.renewal-reminder.customer', new CatalogNotificationTemplate(
    'subscriptions.renewal-reminder.customer',
    static fn ( ?string $locale ): string => __( 'Renewal reminder', [], $locale ),
    'transactional',
    [ 'Store.name', 'Subscription.renews_at', 'Customer.first_name' ],
    [ 'Store' => [ … ], 'Subscription' => [ … ], 'Customer' => [ … ] ],
    static fn ( ?string $locale ): string => __( 'Your subscription renews soon', [], $locale ),
    static fn ( ?string $locale ): string => __( '<p>Hi {{ Customer.first_name }}, …</p>', [], $locale ),
) );

app( NotificationDispatcher::class )->send(
    'subscriptions.renewal-reminder.customer',
    $customer,
    [ 'Subscription' => [ … ], 'Customer' => [ … ] ],
    $subscription,
    locale: $subscription->order->locale, // optional
);
```

`NotificationTemplate::defaultSubject( ?string $locale = null )` and
`defaultBody( ?string $locale = null )` take the locale to translate into
(null means the current app locale). `send()` takes an optional `$locale`;
without it, each customer recipient's own preference applies, and otherwise
the app locale at delivery. Staff notifications are sent in the store default
(`NotificationDispatcher::adminLocale()`).

## Editable templates

Each catalog entry has a row in `notification_templates` for each channel
and locale. The editor API edits existing rows. Only default-locale rows are
seeded, so rows for other locales come from your own seeder or a satellite. The row holds the `subject` and `body` as **Twig** source, the
declared `variables`, `preview_data`, and `is_active`. Rows in the default
locale (`notifications.default_locale`, falling back to
`app.fallback_locale`) are seeded from the catalog the first time the
templates are listed. Seeding keeps any edits already made. It also refreshes
each row's declared variables from the catalog definition.

At send time the copy for the notification's locale is, in order:

1. the store's row in that locale;
2. the catalog default translated into that locale, when the locale (or its
   base language) is in `localization.supported_locales` and isn't the
   default locale;
3. the store's default-locale row;
4. the catalog default.

Rows with `is_active` set to false aren't sent. See
[localization](./localization.md#the-shoppers-language) for how the locale is
chosen.

Templates see plain data, never models. Variables are nested arrays under a
few roots: `Store`, `Order`, `Customer`, `Shipment`, `Refund`, `Review`,
`Product`, `Downloads`, `Licenses`, `License`, `Activation`, `File`, and
`InventoryItem`. Money arrives formatted (`$50.36`) and dates arrive as
ISO 8601 strings, so format them with the `localized_date` filter (Twig's own
`date` filter always prints English month names). For example:

```twig
Hi {{ Order.customer.first_name|default(Order.customer.name) }},
{% for item in Order.items %}{{ item.name }} × {{ item.quantity }} — {{ item.total }}{% endfor %}
```

Two variables are per recipient:

- `Store.preferences_url` is the recipient's signed unsubscribe link when the
  template's category can be turned off, and otherwise
  `notifications.preferences_url` (your storefront's settings page, if set).
- `Order.view_url` is set for guest orders only: a signed link that shows the
  order without signing in (`checkout.order_view_url` with `{token}`
  replaced, else the REST `order-views/{token}` endpoint). The order
  confirmation links to it.

### The sandbox

Templates render with `twig/twig`'s `SecurityPolicy` sandbox turned on for
every template:

- **Tags:** `if`, `for`, `apply`. Nothing else, so no `include`, `import`,
  `macro`, `embed`, `extends`, or `set`. Repeating `{% set s = s ~ s %}`
  doubles a string until PHP runs out of memory.
- **Filters:** `abs`, `capitalize`, `date`, `default`, `e` / `escape`,
  `first`, `join`, `keys`, `last`, `length`, `localized_date`, `lower`, `merge`, `nl2br`,
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

### The mail

Each mail goes out as HTML plus a `text/plain` alternative:

- The rendered body is wrapped in a minimal document with the recipient's
  language and direction (`<html lang="de" dir="ltr">`, `dir="rtl"` for
  right-to-left languages) and the subject as its `<title>`.
- The text part is converted from the HTML: block elements become line
  breaks, list items get a dash, and links keep their target as
  `text (https://…)`.
- The catalog's layout tables carry `role="presentation"` for screen readers.

Mail in a category the recipient can turn off (anything but transactional)
also carries one-click unsubscribe:

- `List-Unsubscribe: <signed URL>` and `List-Unsubscribe-Post:
  List-Unsubscribe=One-Click` headers (RFC 8058);
- a footer link, "Unsubscribe from these emails", that the store's copy can't
  remove.

The signed URL never expires. `GET ecommerce/notifications/unsubscribe` shows
a confirmation page with a button, so a mail scanner fetching the link
changes nothing; `POST` to the same URL, which is what mail clients send for
one-click, turns the category off. A guest who unsubscribes gets a customer
record for their address so the choice sticks, and later mail to that
address checks it. Both routes are rate-limited per IP
(`rate_limits.notifications.unsubscribe.per_ip`, default 30 a minute) and
answer in the `Accept-Language` language.

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
- Mail to a guest order's address follows the preferences of the customer
  record with that email, if there is one (see the unsubscribe link above).

| Method | Path | Access |
|---|---|---|
| GET | `me/notification-preferences` | Signed-in shopper with a storefront-capable token. Lists every channel × category with `is_enabled` and `is_locked`. |
| PATCH | `me/notification-preferences` | Same access, plus an Idempotency-Key. Body: `{ preferences: [ { channel, category, is_enabled } ] }`. |

Preference channels come from `notifications.preference_channels` (default
`[ 'mail' ]`).
