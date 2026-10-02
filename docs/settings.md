# Store settings

The engine is configured through `config/artisanpack/ecommerce.php` and env.
An allow-listed subset of those keys can also be changed from an admin, so a
store owner can change the support e-mail or the tax provider without a deploy
(parent plan §11.1, engine issue #145).

## How it works

- Stored values live in the `ecommerce_settings` table (`key`, JSON `value`).
- `SettingsRegistry` is the allow-list: groups, the keys in each, their type,
  and their validation rules. Only these keys can be stored.
- `SettingsRepository` reads and writes them. Reads fall back to config. The
  stored rows are cached for ten minutes under `ap.ecommerce.settings`, and the cache
  is forgotten once a write commits.
- Each stored value is applied on top of config when its key is defined, early
  in the engine's `boot()`. Code that reads an allow-listed key with `config()`
  therefore gets the admin's value. Queue workers re-apply the values before
  every job, so a long-running worker sees changes made after it started.
- Secrets (gateway keys, webhook signing secrets) are never stored, returned,
  or editable. A group lists them only as configured or not configured.

```php
ecommerceSetting( 'tax.provider' );                       // stored value, else config
app( SettingsRepository::class )->values( 'checkout' );   // every key in a group
app( SettingsRepository::class )->update( 'checkout', [ 'checkout.reservation_ttl_minutes' => 20 ] );
app( SettingsRepository::class )->forget( 'checkout', [ 'checkout.reservation_ttl_minutes' ] ); // back to config
```

`update()` and `forget()` throw `SettingsWriteException` (422
`settings-write-failed` over REST) for an unknown group or key, a value that
fails its rules, or an unconfirmed base-currency change. Values are coerced to
their type first, so `"15"` stores as `15` and `"0"` as `false`.

Some values are read once at boot, so a change takes effect on the next
request or job: whether the Stripe gateway is registered, and whether
notification listeners are wired.

## Core groups and keys

| Group | Keys |
|---|---|
| `general` | `notifications.store_name`, `notifications.support_email`, `base_currency`, `timezone` |
| `checkout` | `checkout.reservation_ttl_minutes` |
| `tax` | `tax.provider`, `tax.prices_include_tax`, `tax.default_class`, `tax.shipping_tax_class`, `localization.tax_labels` |
| `shipping` | `fulfillment.allocation_strategy` |
| `payments` | `gateways.stripe.enabled`, `gateways.stripe.capture_method`, `fraud.provider`; secrets: Stripe secret key, publishable key, webhook signing secret |
| `notifications` | `notifications.enabled`, `notifications.admin_emails`, `notifications.default_locale`, `notifications.preference_channels`, `notifications.review_request_delay_days` |
| `reviews` | `reviews.allow_guests` |
| `digital` | `digital.auto_issue`, `digital.download_limit`, `digital.download_expiry_days`, `digital.revoke_on_refund` |
| `licenses` | `licenses.activations_limit`, `licenses.expires_in_days` |
| `kanban` | `kanban.auto_route`, `kanban.stale_after_days`, `kanban.default_card_widgets` |

Keys are relative to `artisanpack.ecommerce`. `timezone` is new in this
release: the store time zone reports bucket by (`null` uses `app.timezone`).

Types tell an admin how to render a key: `string`, `text`, `email`, `integer`,
`boolean`, `select`, `multiselect`, `list`, `map`, `currency`, `timezone`.
`select` and `multiselect` options come from the matching registry (tax
providers, allocation strategies, card widgets) when the form is built.

## Base currency

Changing `base_currency` needs explicit confirmation:
`update( 'general', [ 'base_currency' => 'EUR' ], confirmBaseCurrencyChange: true )`,
or `confirm_base_currency_change: true` over REST. Without it the write is
refused with the code `base-currency-change-unconfirmed`.

Per plan §16.4, existing orders keep the `base_currency` and
`fx_rate_to_base_e8` they were placed with. New orders adopt the new base.
Reports convert old orders to the new base at today's cross-rate and flag them
(see [reports.md](reports.md)). After the change, the
`ap.ecommerce.settings.baseCurrencyChanged` action fires with the old and new
codes.

## Adding settings from a satellite

```php
use ArtisanPackUI\Ecommerce\Registries\SettingsRegistry;
use ArtisanPackUI\Ecommerce\Settings\SettingDefinition;
use ArtisanPackUI\Ecommerce\Settings\SettingSecret;

public function boot(): void
{
    app( SettingsRegistry::class )
        ->addGroup( 'paypal', __( 'PayPal' ), 55 )
        ->define( new SettingDefinition(
            key: 'paypal.enabled',
            group: 'paypal',
            type: 'boolean',
            label: __( 'Enable PayPal' ),
            configKey: 'artisanpack.ecommerce-paypal.enabled',
        ) )
        ->addSecret( new SettingSecret( 'artisanpack.ecommerce-paypal.client_secret', 'paypal', __( 'Client secret' ), 'PAYPAL_CLIENT_SECRET' ) );
}
```

A satellite may also add keys to a core group (`'payments'`). Defining a key
whose config path is a registered secret, or a key that already exists, throws.

## REST

| Method | Path | Ability |
|---|---|---|
| `GET` | `admin/settings` | `settings.view` |
| `GET` | `admin/settings/{group}` | `settings.view` |
| `PATCH` | `admin/settings/{group}` | `settings.update` (Idempotency-Key required) |

`GET admin/settings/{group}` returns the group, each setting (definition,
`value`, and `stored`, which is true when the value comes from the table rather
than config), and each secret's `configured` flag. `PATCH` takes
`{ values: { key: value }, reset: [ key ], confirm_base_currency_change }` and
returns the same payload with `meta.changed`.

## Hooks

`ap.ecommerce.settings.updated` (`$group`, `$changes`) and
`ap.ecommerce.settings.baseCurrencyChanged` (`$from`, `$to`) fire after commit.
See [hooks.md](hooks.md#settings).
