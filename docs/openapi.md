# OpenAPI spec

The REST API is described by an OpenAPI 3.1 document generated from the code
(parent plan §12.1), so it can't drift from what the API actually does:

| Spec content | Source |
|---|---|
| Paths, methods, path parameters | The registered `ecommerce.api.*` routes (plus the inbound `ecommerce/webhooks/{provider}` route) |
| Auth + required ability (`x-ecommerce-ability`, `x-token-scopes`) | `auth:*` and `ecommerce.can:{resource},{action}` route middleware |
| Rate-limit policy (`x-rate-limit-policy`) | `ecommerce.rate-limit:{policy}` route middleware, on every endpoint |
| `Idempotency-Key` header (`x-idempotency: required`) | `ecommerce.idempotency` route middleware, on every mutating endpoint |
| Request bodies | The action's FormRequest `rules()` |
| Summaries, response resource, status | The `#[ApiOperation]` attribute on the controller action |
| Component schemas | `ArtisanPackUI\Ecommerce\Api\ResourceSchemas` (the same definitions the GraphQL types use) |
| Error responses | `application/problem+json` for 400/401/403/404/409/422/429 |
| Outbound webhook events | The 3.1 `webhooks` section, from `webhooks.events` |

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

The spec reflects the application's config: route prefix, API version, and
the webhook event list.

## Release artifact

The `Release` workflow generates the spec for every `v*` tag, validates it with
Redocly (`@redocly/cli lint`), and attaches
`ecommerce-openapi-v{version}.json` to the GitHub release.

## Documenting a new endpoint

Add the route with its auth / rate-limit / idempotency middleware as usual,
type-hint a FormRequest if it takes a body, and annotate the action:

```php
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;

#[ApiOperation( summary: 'Issue a refund against an order', resource: RefundResource::class, status: 201 )]
public function store( IssueRefundRequest $request, Order $order ): JsonResponse
```

`tests/Feature/OpenApi/OpenApiGeneratorTest.php` fails if a route is
undocumented, has no rate-limit policy, or is a mutating route without
`ecommerce.idempotency`.
