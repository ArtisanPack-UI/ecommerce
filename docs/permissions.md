# Permissions

How the engine decides who may do what, and how to grant it. The details for
each caller type (Sanctum tokens, SPA sessions, signed service requests) are in
[api-auth.md](api-auth.md).

## Abilities

Every admin action is an ability named `ecommerce.{resource}.{action}`, for
example `ecommerce.order.refund` or `ecommerce.taxRate.update`. The REST
`ecommerce.can` middleware, the engine's policies, and the GraphQL resolvers
all ask `Auth\EcommerceAuthorizer`, so the three surfaces always agree.

Decisions are **default-deny**:

1. A signed service caller is decided by its configured abilities alone.
2. Otherwise, if the host app defines the Gate ability
   `ecommerce.{resource}.{action}`, it decides.
3. Otherwise, if the host app defines the umbrella ability `ecommerce.admin`,
   it decides.
4. Otherwise the action is denied.

A store with no Gate definitions and no cms-framework can't use any admin
endpoint. The smallest setup is one umbrella Gate:

```php
// AppServiceProvider::boot()
Gate::define( 'ecommerce.admin', fn ( User $user ): bool => $user->is_admin );

// Narrow one action further:
Gate::define( 'ecommerce.order.refund', fn ( User $user, ?Order $order = null ): bool => $user->hasRole( 'finance' ) );
```

Over REST, the Gate gets the first route parameter bound to a model (the
`Order` for `orders/{order}/refunds`), or nothing when the route has none.

### The ability filter

The result then runs through the `ap.ecommerce.abilities.{resource}.{action}`
filter, with `(bool $allowed, $user, Request $request, mixed $subject)`. Role
satellites use it to grant or revoke without defining a Gate:

```php
addFilter( 'ap.ecommerce.abilities.order.refund', function ( bool $allowed, $user ): bool {
    return $allowed && null !== $user->email_verified_at;
} );
```

Finally, the caller's token abilities narrow the result (see below). A filter
can't widen what a scoped token was issued for.

### The ability catalog

`Auth\AbilityCatalog::CORE` lists every engine resource and its actions. The
policy table in [api-auth.md](api-auth.md#policies) shows the same list by
model. Satellites add their own resources through the
`ap.ecommerce.abilities.catalog` filter, so integrations such as cms-framework
pick them up:

```php
addFilter( 'ap.ecommerce.abilities.catalog', fn ( array $catalog ): array => $catalog + [
    'subscription' => [ 'viewAny', 'view', 'update', 'cancel' ],
] );
```

`AbilityCatalog::abilities()` returns every ability name after the filter.

## Token scopes

A Sanctum token's abilities only **narrow** access. The user's abilities still
decide whether they may act at all. Cookie-session requests aren't narrowed.

| Token ability | Reaches |
|---|---|
| `ecommerce:admin` | Every admin endpoint the user is allowed |
| `ecommerce:storefront` | Shopper surfaces only (`me`, own orders, own cart); never an admin endpoint, even for an admin user |
| `ecommerce:{resources}.read` | `view` and `viewAny` on one resource family, e.g. `ecommerce:orders.read` |
| `ecommerce:{resources}.write` | Every other action on that family, e.g. `ecommerce:tax-rates.write` |
| `*` | Sanctum's default when no abilities are given; behaves like `ecommerce:admin` |

Actions that move money or destroy data need their own scope. The resource's
`.write` scope isn't enough for them:

| Action | Scope |
|---|---|
| `order.refund` | `ecommerce:orders.refund` |
| `order.cancel` | `ecommerce:orders.cancel` |
| `customer.delete` | `ecommerce:customers.delete` |
| `settings.update` | `ecommerce:settings.write` |

`ecommerce:admin` covers all four. `TokenAbilities::forAction( $resource,
$action )` returns the scope an action needs, and `TokenAbilities::scope()`
builds resource scopes:

```php
use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;

$user->createToken( 'reporting', [ TokenAbilities::scope( 'order', 'read' ) ] );   // ecommerce:orders.read
$user->createToken( 'refunds', [ TokenAbilities::forAction( 'order', 'refund' ) ] ); // ecommerce:orders.refund
```

## cms-framework

When `artisanpack-ui/cms-framework` is installed and
`cms_framework.enabled` is `true` (the default), the engine:

- registers every ability in the catalog as an RBAC permission with the same
  slug (`ecommerce.order.refund`);
- creates a `shop-manager` role that holds all of them;
- defines each ability as a Gate that defers to `ecommerce.admin`, so
  cms-framework's `Gate::before` can grant it from the permission. A Gate the
  host defines for the same ability wins.

Assign the `shop-manager` role to give a user the whole store, or grant single
permissions for narrower roles.

Permissions are synced after every `php artisan migrate` (forward migrations
only, not `--pretend`), and on demand:

```bash
php artisan ecommerce:sync-permissions
```

Syncing is safe to repeat. It never takes a permission away from a role. If the
sync fails during `migrate` (for example, cms-framework's tables aren't
migrated yet), the migration still succeeds and a warning is logged; run the
command afterwards. Run it again after installing a satellite that adds
abilities.

Set `cms_framework.enabled` to `false` (`ECOMMERCE_CMS_FRAMEWORK_ENABLED`) to
manage permissions yourself with Gates.

Admin navigation isn't wired here. Each admin satellite adds its own menu to
cms-framework.
