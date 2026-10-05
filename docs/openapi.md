# OpenAPI spec

The REST API is described by an OpenAPI 3.1 document generated from the code
(parent plan §12.1), so it can't drift from what the API actually does:

| Spec content | Source |
|---|---|
| Paths, methods, path parameters | The registered `ecommerce.api.*` routes (plus the inbound `ecommerce/webhooks/{provider}` route) |
| Auth + required ability (`x-ecommerce-ability`, `x-token-scopes`) | `auth:*` and `ecommerce.can:{resource},{action}` route middleware. `x-token-scopes` lists `ecommerce:admin` and the one scope the action needs, including the dedicated refund, cancel, and customer-delete scopes. |
| Rate-limit policy (`x-rate-limit-policy`) | `ecommerce.rate-limit:{policy}` route middleware, on every endpoint |
| `Idempotency-Key` header (`x-idempotency: required`) | `ecommerce.idempotency` route middleware, on every mutating endpoint |
| Request bodies | The action's FormRequest `rules()` |
| List query parameters (`filter` as a deepObject, `sort`, `include`, `per_page`, `cursor`) | The `filters`, `sorts`, and `includes` of the `#[ApiOperation]` attribute. `per_page` is capped at `api.max_per_page`, and an `int-list` filter gets the pattern `^[0-9]+(,[0-9]+)*$`. |
| Other query parameters | A GET action's FormRequest `rules()`, plus the attribute's `query` entries (for example the catalog's `q`, `currency`, `attributes`, `facets`, and `page`) |
| Summaries, response resource, status | The `#[ApiOperation]` attribute on the controller action |
| Success bodies that aren't a resource | `ArtisanPackUI\Ecommerce\OpenApi\ResponseSchemas`, keyed by operationId (catalogs, reports, settings, previews, quotes, …). File downloads are `application/octet-stream` with `200`, `206`, and `416` responses. |
| Component schemas | `ArtisanPackUI\Ecommerce\Api\ResourceSchemas` (the same definitions the GraphQL types use) |
| Error responses | `application/problem+json` for 400/401/403/404/409/422/429 |
| Outbound webhook events | The 3.1 `webhooks` section, from `webhooks.events` |

The signed unsubscribe links (`ecommerce/notifications/unsubscribe`) return
HTML pages, so they aren't in the spec. See
[api.md](api.md#unsubscribe-links).

## Generating

In an application:

```bash
php artisan ecommerce:generate-openapi                         # storage/app/ecommerce-openapi.json
php artisan ecommerce:generate-openapi --output=public/openapi.json
php artisan ecommerce:generate-openapi --stdout
```

From the package itself (no host app), through Orchestra Testbench:

```bash
vendor/bin/testbench ecommerce:generate-openapi --output=build/openapi/ecommerce-openapi.json
```

The spec reflects the application's config: route prefix, API version,
`api.max_per_page`, and the webhook event list.

## Release artifact

The `Release` workflow generates the spec for every `v*` tag, validates it with
Redocly (`npx @redocly/cli@2 lint`), and attaches
`ecommerce-openapi-v{version}.json` to the GitHub release.

## Documenting a new endpoint

Add the route with its auth / rate-limit / idempotency middleware as usual,
type-hint a FormRequest if it takes a body, and annotate the action:

```php
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;

#[ApiOperation( summary: 'Issue a refund against an order', resource: RefundResource::class, status: 201 )]
public function store( IssueRefundRequest $request, Order $order ): JsonResponse
```

A list declares what it accepts, and the spec builds the query parameters from
it:

```php
#[ApiOperation(
    summary: 'List inventory items',
    resource: InventoryItemResource::class,
    collection: true,
    filters: [ 'stockable_id' => 'int-list', 'warehouse_id' => 'int-list', 'low_stock' => 'string' ],
    sorts: [ 'quantity_on_hand' ],
    includes: [ 'reservations' ],
)]
```

An action whose body isn't a resource needs an entry in `ResponseSchemas::for()`
under its operationId.

Two tests guard the spec. `tests/Feature/OpenApi/OpenApiGeneratorTest.php`
fails if a route is undocumented, has no rate-limit policy, is a mutating route
without `ecommerce.idempotency`, has a `$ref` that doesn't resolve, or has a
success response without a real schema.
`tests/Feature/OpenApi/OpenApiParityTest.php` calls every documented list with
each documented filter, sort, and include, and fails if the API refuses one.
