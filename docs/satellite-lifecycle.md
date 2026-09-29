# Satellite lifecycle

Satellites (subscriptions, shipping labels, gift cards, …) install with the
usual `composer require` + `php artisan vendor:publish` + `php artisan migrate`.
Uninstalling is a first-class engine command that **preserves data by
default**, so reinstalling later picks up where the store left off. Dropping a
satellite's tables is an explicit `--purge`.

See parent plan §16.6 and engine spec §3.32 / §5 row 16.

## Requirements on every satellite

1. **Register with the `SatelliteRegistry`** in your service provider's
   `boot()`, and guard your wiring on the result:

   ```php
   use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;

   public function boot(): void
   {
       $active = $this->app->make( SatelliteRegistry::class )->register( [
           'package_name'    => 'acme/ecommerce-subscriptions',
           'version'         => '1.2.0',
           'label'           => __( 'Subscriptions' ),
           'migration_paths' => [ __DIR__ . '/../database/migrations' ],
           'config_keys'     => [ 'ecommerce-subscriptions' ],
           'meta_namespaces' => [ 'orders.meta.subscription', 'cart.meta.subscription' ],
           'tables'          => [ 'subscriptions', 'subscription_cycles' ],
           'columns'         => [ 'orders' => [ 'subscription_id' ] ],
           'product_types'   => [ 'subscription' ],
           'uninstaller'     => SubscriptionsUninstaller::class,
       ] );

       if ( ! $active ) {
           return; // Uninstalled: register no routes, config, bindings, or hooks.
       }

       // …routes, config, product types, listeners, scheduled jobs…
   }
   ```

   `register()` only keeps the descriptor in memory — nothing is written per
   request. The `ecommerce:satellite:*` commands persist it to the
   `ecommerce_satellites` table (`SatelliteRegistry::sync()`).

   | Key               | Meaning                                                                                              |
   |-------------------|------------------------------------------------------------------------------------------------------|
   | `package_name`    | Composer name (`vendor/package`). Required, unique.                                                  |
   | `version`         | Installed version. Required.                                                                         |
   | `label`           | Display name.                                                                                        |
   | `migration_paths` | Directories holding **only your** migrations (absolute, or relative to the app base path). Used by `--purge`. A path that is, or contains, the app's `database/migrations` directory, the engine's migrations, or the app base path is refused — `--purge` rolls back every ran migration under the given paths. A dedicated sub-directory such as `database/migrations/my-satellite` is fine. |
   | `config_keys`     | Config files / keys you publish.                                                                     |
   | `meta_namespaces` | `orders.meta` / `cart.meta` namespaces you own.                                                      |
   | `tables`          | Tables you create.                                                                                   |
   | `columns`         | Columns you add to tables you don't own, keyed by table.                                             |
   | `product_types`   | Product type keys you register.                                                                      |
   | `uninstaller`     | Class implementing `ArtisanPackUI\Ecommerce\Contracts\SatelliteUninstaller`.                         |

2. **Ship full `up()` and `down()` migrations** for every table and column.
   `down()` must be idempotent (`Schema::dropIfExists`, `hasColumn` guards) and
   safe on any prior schema version — `--purge` runs it.

3. **Ship a `SatelliteUninstaller`** when you own long-lived services:

   ```php
   use ArtisanPackUI\Ecommerce\Contracts\SatelliteUninstaller;
   use ArtisanPackUI\Ecommerce\Satellites\SatelliteDescriptor;

   final class SubscriptionsUninstaller implements SatelliteUninstaller
   {
       public function uninstall( SatelliteDescriptor $satellite, bool $purge ): void
       {
           // Cancel remote renewal webhooks, stop queue consumers, forget
           // cached schedules, … Must be idempotent. Don't drop tables —
           // with --purge the engine rolls your migrations back afterwards.
       }
   }
   ```

## Active vs. uninstalled

A satellite is **active** unless `ecommerce:satellite:uninstall` marked it
uninstalled. An uninstalled satellite stays inactive — even while its package
is still installed — until `ecommerce:satellite:reinstall` re-attaches it.
`register()` returns `false` for it, and `SatelliteRegistry::isActive()` answers
the same question anywhere else.

The uninstalled set is read from the database once and cached (cache key
`artisanpack.ecommerce.satellites.uninstalled`); the lifecycle commands bust the
cache. Before the `ecommerce_satellites` table exists, or when the database is
unreachable, every satellite counts as active.

As a safety net, the product types an inactive satellite declares are removed
from the `ProductTypeRegistry` once the app has booted, even if the satellite
registered them without checking `register()`'s result.

## Commands

### `ecommerce:satellite:uninstall {package}`

```bash
# Deregister: run the uninstaller, stop wiring routes/config/bindings,
# clear cached config + routes. Tables and rows are preserved.
php artisan ecommerce:satellite:uninstall acme/ecommerce-subscriptions

# Same, and also roll back the satellite's migrations (drops its tables/columns).
php artisan ecommerce:satellite:uninstall acme/ecommerce-subscriptions --purge
```

- Prompts for confirmation unless `--force` is given. Non-interactive runs
  (`-n`, deploy scripts, CI) and production refuse without `--force`.
- With `--purge`, validates every migration path before anything runs and
  aborts the whole uninstall if one is shared with the app or the engine.
- A satellite may only take product types out of the registry that it
  actually provides: engine types (`simple`, `digital`, …) and keys another
  active satellite also declares are left alone.
- Works for a satellite that is still installed or one that only exists as a
  row (e.g. after `composer remove`).
- `--purge` rolls back every migration found in the satellite's stored
  `migration_paths`, so the migration files must still be on disk: **purge
  before `composer remove`**. Missing paths are skipped with a warning.
- Running it again with `--purge` on an already-uninstalled satellite purges it.

Then remove the package: `composer remove acme/ecommerce-subscriptions`.

### `ecommerce:satellite:reinstall {package}`

Clears the uninstalled mark. The satellite re-attaches to its preserved data on
the next boot. If it was purged, run `php artisan migrate` first.

### `ecommerce:satellite:audit`

Lists every satellite the engine knows about (`active`, `uninstalled`, or
`not-registered` — its row exists but the package no longer boots) and the
**orphaned** schema they left behind: tables and columns declared by an
uninstalled or unregistered satellite that still exist and that no active
satellite claims. This catches a satellite replaced by a differently-named one.

```bash
php artisan ecommerce:satellite:audit
php artisan ecommerce:satellite:audit --json
php artisan ecommerce:satellite:audit --fail-on-orphans   # exit 1 when anything is orphaned
```

The audit is advisory: orphans are harmless (Eloquent ignores unknown columns),
and reinstalling the satellite claims them again.

## Missing product types

A product whose `type` is no longer registered (e.g. `subscription` after the
subscriptions satellite is uninstalled) resolves to the `MissingProductType`
placeholder:

- It stays queryable and listed — the REST/GraphQL product resource carries
  `type_missing: true` and (to admin callers only) a translated `type_warning` for admin UIs to show
  as a banner.
- It is **read-only**: `ProductPolicy::update()` denies every user, and
  `Product::isEditable()` returns `false`.
- It **cannot be sold**: adding it to a cart fails with a `422`
  `product-type-missing` problem, and pricing a line throws a
  `CartOperationException`.
- Historical orders are untouched. When the satellite is reinstalled, its
  products become editable and sellable again.
