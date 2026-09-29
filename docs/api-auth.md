# API authentication and authorization

The REST API (`/api/ecommerce/v1`) and the GraphQL endpoint
(`/graphql/ecommerce`) accept three kinds of caller (parent plan §12.1):

| Caller | How it authenticates | Typical use |
|---|---|---|
| Headless client / admin tool | Sanctum personal access token (`Authorization: Bearer …`) | React / mobile storefronts, back-office apps |
| First-party storefront | Sanctum SPA cookie session | Blade / Livewire / Inertia storefront on the same domain |
| Internal service | HMAC-signed request (`Authorization: Signature …`) | Satellite → engine RPC, ERP sync |

Every caller then goes through the same authorization decision, whether the
request arrives over REST, GraphQL, or a `$user->can()` call in your own code.

## Abilities

Each action is an ability named `ecommerce.{resource}.{action}` (engine spec
§6.18), for example `ecommerce.order.refund` or `ecommerce.taxRate.update`.
Decisions are **default-deny**:

1. If the host app defines the Gate ability `ecommerce.{resource}.{action}`, it decides.
2. Otherwise, if it defines the umbrella ability `ecommerce.admin`, that decides.
   This fallback means a store without cms-framework roles still works.
3. Otherwise the action is denied.

```php
// AppServiceProvider::boot()
Gate::define( 'ecommerce.admin', fn ( User $user ): bool => $user->is_admin );

// Narrow one action further:
Gate::define( 'ecommerce.order.refund', fn ( User $user, $subject ): bool => $user->hasRole( 'finance' ) );
```

The decision then runs through the
`ap.ecommerce.abilities.{resource}.{action}` filter, which gets
`(bool $allowed, $user, Request $request, mixed $subject)`. That lets role
satellites grant or revoke without defining a Gate. `$subject` is the model when
the check comes from a policy or GraphQL, and the request when it comes from the
REST middleware.

### Policies

The engine registers a Laravel policy for each of its models, all backed by the
same decision, so the usual Laravel authorization calls work:

```php
$user->can( 'refund', $order );          // ecommerce.order.refund
$user->can( 'edit-fulfilled', $order );  // ecommerce.order.edit-fulfilled
Gate::authorize( 'viewAny', Product::class );
```

| Policy | Models | Actions |
|---|---|---|
| `ProductPolicy` | `Product` | `viewAny`, `view`, `create`, `update`, `delete`, `restore` |
| `OrderPolicy` | `Order` | `viewAny`, `view`, `create`, `update`, `edit-fulfilled`, `cancel`, `refund` |
| `RefundPolicy` | `Refund` | `view`, `create` |
| `CustomerPolicy` | `Customer` | `viewAny`, `view`, `update`, `delete` |
| `PromotionPolicy` | `Promotion` | `viewAny`, `view`, `create`, `update`, `delete` |
| `CouponPolicy` | `Coupon` | `create`, `update`, `delete` |
| `TaxRatePolicy` | `TaxRate`, `TaxClass` | `viewAny`, `create`, `update`, `delete` |
| `ShippingZonePolicy` | `ShippingZone`, `ShippingMethod` | `viewAny`, `create`, `update`, `delete` |
| `WebhookSubscriptionPolicy` | `WebhookSubscription` | `viewAny`, `create`, `update`, `delete` |
| `KanbanBoardPolicy` | `KanbanBoard`, `KanbanColumn`, `KanbanAutomation` | `viewAny`, `view`, `create`, `update`, `delete` |
| `KanbanCardPolicy` | `OrderBoardAssignment` | `move` |

`OrderPolicy::view()` also lets a shopper see their own order (the order's
customer is linked to their user id) when their token allows storefront access.
A policy the host app already registered for one of these models takes
precedence. Review, digital-delivery, and notification policies arrive with
those subsystems.

## Sanctum token abilities

A token's abilities **narrow** what it can do. They never grant more than the
user's abilities allow:

| Token ability | Reaches |
|---|---|
| `ecommerce:admin` | Every admin endpoint the user is allowed |
| `ecommerce:storefront` | Shopper surfaces only (`me`, `myOrders`, own `order`), never an admin endpoint, even for an admin user |
| `ecommerce:{resources}.read` / `.write` | One resource family, e.g. `ecommerce:orders.read`, `ecommerce:tax-rates.write` |
| `*` | Sanctum's default when no abilities are given: behaves like `ecommerce:admin` |

`view` / `viewAny` need `.read`; every other action needs `.write`.
`TokenAbilities::scope()` builds the names:

```php
use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;

// Headless admin client
$user->createToken( 'backoffice', [ TokenAbilities::ADMIN ] );

// Mobile storefront
$user->createToken( 'ios-app', [ TokenAbilities::STOREFRONT ] );

// Integration that may only read orders
$user->createToken( 'reporting', [ TokenAbilities::scope( 'order', 'read' ) ] ); // ecommerce:orders.read
```

Cookie-session requests (Sanctum's `TransientToken`) aren't narrowed. The
ability filter can't widen a narrowed token.

## Service-to-service signatures

For internal callers that aren't users, configure a shared secret and the
abilities the service gets:

```php
// config/artisanpack/ecommerce.php
'api' => [
    'services' => [
        'erp-sync' => [
            'secret'    => env( 'ECOMMERCE_ERP_SECRET' ),
            'abilities' => [ 'ecommerce:orders.read', 'ecommerce:orders.write' ],
        ],
    ],
    'signature_tolerance_seconds' => 300,
],
```

The service signs each request (engine spec §11.4):

```
Authorization: Signature keyId="erp-sync",algorithm="hmac-sha256",headers="(request-target) host date digest",signature="{base64}"
Date: Tue, 29 Sep 2026 12:00:00 GMT
Digest: SHA-256={base64(sha256(body))}
```

The signature is `base64( hmac_sha256( signingString, secret ) )`. The signing
string is each covered header as `name: value`, joined with `\n`, where
`(request-target)` is the lowercase method, a space, and the path with its query
string. PHP callers can use the helper:

```php
$headers = ArtisanPackUI\Ecommerce\Auth\ServiceSignature::sign(
    'erp-sync', $secret, 'GET', '/api/ecommerce/v1/orders?filter[payment_status]=paid', 'shop.example.com', '',
);
```

A request is rejected with `401` (`problems/invalid-service-signature`) if the
service is unknown (`keyId` must match `[A-Za-z0-9_-]`), the algorithm or
covered headers are wrong, the `Date` isn't an RFC 7231 date
(`Tue, 29 Sep 2026 12:00:00 GMT`) within the tolerance, the `Digest` doesn't match the body, the signature
doesn't match, or the same signature was already used. The authenticated
principal is a `ServiceActor`. It's decided by its configured abilities alone,
so host Gate callbacks written for user rows never see it. Idempotency records
are keyed to `service:{name}`.

Replay protection is recorded in the default cache store. On more than one
server, that store must be shared (Redis, database, Memcached); otherwise a
captured request could be replayed against another node within the tolerance
window.
