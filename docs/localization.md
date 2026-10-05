# Localization

The engine ships every user-facing string in four locales on day one — `en`,
`es`, `fr`, and `de` — formats money and dates for the active locale, and
fails the build when an untranslated string slips in.

See parent plan §16.5.

## Requirements

The engine requires PHP's **`intl` extension** (`ext-intl`). Money formatting
is built on `NumberFormatter`, so installation fails fast when the extension
is missing rather than silently printing unformatted amounts.

## Catalogues

Every user-facing string goes through `__()` or `trans_choice()` with its
English source as the key:

```php
__( 'That coupon code is not valid.' );
__( 'Column ":column" is at its limit of :limit cards.', [ 'column' => $name, 'limit' => 5 ] );
trans_choice( ':count item|:count items', $count, [ 'count' => $count ] );
```

The catalogues are JSON files keyed by that English source:

| File | Locale |
|---|---|
| `lang/en.json` | English (identity — the canonical key list) |
| `lang/es.json` | Spanish |
| `lang/fr.json` | French |
| `lang/de.json` | German |

The service provider registers them with `loadJsonTranslationsFrom()`, so they
resolve with no setup. To edit a translation in place, publish them:

```bash
php artisan vendor:publish --tag=ecommerce-lang
```

This copies them to `lang/vendor/ecommerce/`, which the engine also loads —
edits there override the shipped catalogues. Precedence, lowest to highest:

1. the shipped `lang/{locale}.json` inside the package;
2. the published copy in `lang/vendor/ecommerce/{locale}.json`;
3. your application's own `lang/{locale}.json`.

To add a locale, copy `en.json` to `{locale}.json` in `lang/vendor/ecommerce/`
(or your application's `lang/`) and translate the values; placeholders
(`:name`) and Twig expressions (`{{ Order.number }}`) must be kept verbatim.

### Regional locales

Laravel never applies locale fallback to JSON keys, so on its own an app
running in `de_DE` would get English engine strings (next to German-formatted
money and dates). The engine wraps the translation loader so a regional
locale (`de_DE`, `es-MX`, `fr_CA`) falls back to its base language's
catalogue (`de`, `es`, `fr`), key by key — a `de_AT.json` still wins for the
keys it defines. This applies to every JSON catalogue in the app, not just the
engine's. Turn it off with `ECOMMERCE_REGIONAL_LOCALE_FALLBACK=false`
(`artisanpack.ecommerce.localization.regional_fallback`).

### Catalogues are app-wide

Laravel's JSON catalogues share one namespace across the whole application.
The engine's generic keys — "Tax", "Status", "Total", "Forbidden", "Key", … —
therefore also translate identical `__()` calls in your own code. If your app
needs a different wording for one of them, define the key in your
`lang/{locale}.json`, which takes precedence (see above).

### What counts as user-facing

A string is **user-facing** when it can reach an end user — a shopper, a store
admin, or an API client:

- `problem+json` titles and details, GraphQL errors, and validation messages;
- messages of exceptions the HTTP / GraphQL layers render (`CartOperationException`,
  `RefundNotAllowedException`, `KanbanOperationException`, …);
- notification subjects and bodies, registry labels, and kanban widget text.

**Developer-facing** strings stay in plain English: log messages, console
command output, registry misuse (`InvalidArgumentException` when a satellite
registers a bad entry), and internal invariant exceptions that are never
rendered to a user.

`InvalidArgumentException` is user-facing only where its message reaches an
API client: the reports (`src/Reports/`) and the refund, cancellation, order
and customer note, and shipment services. The lint treats it that way only in
those paths (`LintTranslationsCommand::USER_FACING_EXCEPTIONS_IN`).

## The translation lint

```bash
vendor/bin/testbench ecommerce:lint:translations      # inside the package
php artisan ecommerce:lint:translations               # inside an app
```

The command fails (non-zero exit) when:

1. **A bare English string reaches a user-facing sink.** Sinks are every
   argument of `Problem::make()`, `abort()`, a validation rule's `$fail()`,
   `ValidationException::withMessages()`, and `new <UserFacingException>()`;
   `parent::__construct()` inside a user-facing exception class; the body of a
   form request's `messages()`; and the value of any `label`, `title`,
   `message`, `description`, `subject`, or `heading` array key. A literal is
   flagged when it reads as prose (a word plus whitespace), so slugs, keys,
   and type names pass. `title` and `description` keys under `OpenApi/`, and
   `description` keys under `GraphQL/`, are developer documentation and
   exempt; so are factories, demo data, contract-test scaffolding, and
   console commands (`Database/Factories/`, `Demo/`, `Testing/`,
   `Console/`). Keys used there are still checked against the catalogues.
2. **A key is missing from a shipped catalogue.** Every literal passed to
   `__()` / `trans_choice()` must exist in all four `lang/*.json` files.

Options:

| Option | Effect |
|---|---|
| `--path=*` | Extra source directories to scan (relative to `base_path()` or absolute). |
| `--lang=` | Catalogue directory to check instead of the engine's `lang/`. |
| `--no-engine` | Scan only the `--path` directories, not the engine's own `src/` (for linting a satellite). |
| `--sync` | Adds every missing key to `en.json` (English as its own translation). The lint keeps failing until `es`, `fr`, and `de` are filled in. |

When a prose literal in a sink is genuinely not interface copy (sample store
data, for instance), annotate it on the same line or the line above. A reason
is required:

```php
// i18n-lint:ignore reason:sample product name (store data), not interface copy
'label' => 'Notes on the Engine (PDF)',
```

CI runs the lint on every pull request (`.github/workflows/translation-lint.yml`).

## Negotiating the language

The REST and GraphQL APIs pick the response language from `Accept-Language`
(the `ecommerce.locale` middleware, added to both stacks even when a
published config lists older middleware). It chooses the best match among
`localization.supported_locales` (default `en`, `es`, `fr`, `de`), uses it as
the app locale for the request, and answers with `Content-Language` and
`Vary: Accept-Language`. A request without the header keeps the app locale.
The previous locale is restored afterwards, so long-lived workers don't leak
it.

```bash
curl -s -X POST "$BASE/carts/$TOKEN/coupons" \
  -H "Accept-Language: de-DE,de;q=0.9" -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" -d '{"code":"NOPE"}'
# → 422 problem+json whose detail is in German; Content-Language: de
```

So problem details, validation messages, and labels come back in the
shopper's language. A cart created during such a request records the
negotiated locale in `carts.locale`.

## The shopper's language

Orders and customers carry a `locale` column:

- **Orders** copy it from the cart at placement (`orders.locale`).
- **Customers** take the locale of their first order when they have none, and
  shoppers set it themselves with `PATCH me` (`{ "locale": "es" }`, one of
  the supported locales). `Customer` implements `HasLocalePreference`.

Both are exposed on the REST resources and the GraphQL `Order` and
`Customer` types.

Notifications go out in the recipient's language: the order's `locale`,
else the customer's preference, else the app locale. Staff notifications
always use the store default (`notifications.default_locale`, else
`app.fallback_locale`). Catalog copy is resolved in this order:

1. the store's own template row in that locale;
2. the shipped catalog copy translated into that locale, when the locale (or
   its base language) is in `supported_locales` and isn't the default;
3. the store's default-locale row;
4. the shipped catalog copy.

See [notifications](./notifications.md).

## Money

`ArtisanPackUI\Ecommerce\Support\MoneyFormatter` formats minor units for the
active locale (or an explicit one):

```php
use ArtisanPackUI\Ecommerce\Support\MoneyFormatter;

MoneyFormatter::format( 123456, 'EUR' );        // en: €1,234.56
MoneyFormatter::format( 123456, 'EUR', 'es' );  // 1.234,56 €
MoneyFormatter::format( 123456, 'EUR', 'fr' );  // 1 234,56 €
MoneyFormatter::format( 123456, 'EUR', 'de' );  // 1.234,56 €
MoneyFormatter::formatMoney( $money );          // a Money\Money instance
```

Fraction digits follow the currency (`JPY` has none, `KWD` has three). When a
locale has no localized symbol for a currency, the ISO code is used with the
locale's grouping — `CHF 1,234.56` in `en`, `CHF 1.234,56` in `de`. A locale
ICU has no data for (`xx`) formats as `en`, whatever the ICU build: newer ICU
rejects it, older ICU silently resolves it to another locale, and both are
treated as unknown. The kanban
`total` widget and every notification money variable (`Order.total`,
`Refund.amount`, …) use it.

## Dates

`ArtisanPackUI\Ecommerce\Support\LocalizedDate::format( $date, ?$format, ?$locale )`
wraps `Carbon::translatedFormat()`, so month and day names are translated.
Moments are shown in the store time zone (`artisanpack.ecommerce.timezone`,
else `app.timezone`); bare `Y-m-d` dates are left as they are. The
default pattern is itself a translatable string (`F j, Y` in English,
`j \d\e F \d\e Y` in Spanish, `j F Y` in French, `j. F Y` in German).

Notification template variables carry dates as ISO 8601 strings. Format them
for the reader with the engine's `localized_date` Twig filter — Twig's own
`date` filter always prints English month names:

```twig
until {{ download.expires_at|localized_date }}
on {{ Order.placed_at|localized_date("j F Y") }}
```

## Tax terminology

`ArtisanPackUI\Ecommerce\Support\TaxLabel::for( ?$locale )` returns the tax line
label: "Tax" (en), "IVA" (es), "TVA" (fr), "USt." (de). Notification templates
receive it as `Order.tax_label`. Store owners override it per locale:

```php
// config/artisanpack/ecommerce.php
'localization' => [
    'tax_labels' => [ 'en' => 'Sales Tax' ],
],
```

An override for a base locale (`en`) also applies to its regional variants
(`en_US`) unless the variant has its own entry.

## Right-to-left

RTL locales are not shipped in v1. Engine-provided markup uses logical CSS
properties (`inline-start` / `inline-end`, `text-align: start`), so adding an
RTL locale is a stylesheet concern rather than a template rewrite.
Notification mail already sets `dir="rtl"` on its `<html>` element for
right-to-left languages (Arabic, Hebrew, Persian, Urdu, and others).
