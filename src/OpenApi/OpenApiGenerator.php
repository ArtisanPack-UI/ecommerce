<?php

/**
 * OpenApiGenerator.
 *
 * Generates the OpenAPI 3.1 document for the ecommerce REST API (parent
 * plan §12.1) from what the application already knows, so the spec can't
 * drift from the code:
 *
 * - **Routes** — every `ecommerce.api.*` route plus the inbound webhook
 *   route: method, path, path parameters.
 * - **Route middleware** — auth (`auth:*`), the required ability
 *   (`ecommerce.can:{resource},{action}` → `x-ecommerce-ability` and the
 *   token scopes that satisfy it), the rate-limit policy
 *   (`ecommerce.rate-limit:{policy}` → `x-rate-limit-policy`), and the
 *   `Idempotency-Key` requirement (`ecommerce.idempotency`).
 * - **Form requests** — the controller action's FormRequest rules become
 *   the request-body schema.
 * - **{@see ApiOperation}** — summary and response resource.
 * - **{@see ResourceSchemas}** — one component schema per resource, the
 *   same definitions the GraphQL types are built from.
 *
 * Outbound webhook events (engine spec §8) are documented under the 3.1
 * top-level `webhooks` key.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\OpenApi;

use ArtisanPackUI\Ecommerce\Api\ResourceSchemas;
use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookPayloadFactory;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookSigner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OpenApiGenerator
{
    /**
     * Route-name prefix of the REST API.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ROUTE_PREFIX = 'ecommerce.api.';

    /**
     * Name of the inbound payment-provider webhook route.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const WEBHOOK_ROUTE = 'ecommerce.webhooks';

    /**
     * @since 1.0.0
     *
     * @param  Router  $router  Router holding the registered routes.
     */
    public function __construct( protected Router $router )
    {
    }

    /**
     * Builds the document.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $base  = '/' . trim( (string) config( 'artisanpack.ecommerce.api_prefix', 'api/ecommerce' ), '/' ) . '/' . config( 'artisanpack.ecommerce.api.version', 'v1' );
        $paths = [];

        foreach ( $this->routes() as $route ) {
            $isApi = str_starts_with( (string) $route->getName(), self::ROUTE_PREFIX );
            $uri   = '/' . ltrim( $route->uri(), '/' );
            $path  = $isApi && str_starts_with( $uri, $base ) ? ( substr( $uri, strlen( $base ) ) ?: '/' ) : $uri;

            foreach ( $route->methods() as $method ) {
                if ( 'HEAD' === $method ) {
                    continue;
                }

                $operation = $this->operation( $route, $method );

                if ( ! $isApi ) {
                    $operation['servers'] = [ [ 'url' => '/' ] ];
                }

                $paths[ $path ][ strtolower( $method ) ] = $operation;
            }
        }

        ksort( $paths );

        return [
            'openapi'           => '3.1.0',
            'jsonSchemaDialect' => 'https://spec.openapis.org/oas/3.1/dialect/base',
            'info'              => [
                'title'       => 'ArtisanPack UI Ecommerce API',
                'version'     => $this->version(),
                'description' => $this->description(),
                'license'     => [ 'name' => 'GPL-3.0-or-later', 'identifier' => 'GPL-3.0-or-later' ],
            ],
            'servers'    => [ [ 'url' => $base, 'description' => 'REST API (relative to the store URL).' ] ],
            'security'   => [],
            'tags'       => $this->tags( $paths ),
            'paths'      => $paths,
            'webhooks'   => $this->webhooks(),
            'components' => [
                'schemas'         => $this->schemas(),
                'responses'       => $this->problemResponses(),
                'parameters'      => $this->parameters(),
                'securitySchemes' => $this->securitySchemes(),
            ],
        ];
    }

    /**
     * The routes to document, ordered by URI.
     *
     * @since 1.0.0
     *
     * @return array<int, Route>
     */
    public function routes(): array
    {
        $routes = array_filter(
            $this->router->getRoutes()->getRoutes(),
            static fn ( Route $route ): bool => str_starts_with( (string) $route->getName(), self::ROUTE_PREFIX ) || self::WEBHOOK_ROUTE === $route->getName(),
        );

        usort( $routes, static fn ( Route $a, Route $b ): int => [ $a->uri(), $a->getName() ] <=> [ $b->uri(), $b->getName() ] );

        return array_values( $routes );
    }

    /**
     * Builds one operation.
     *
     * @since 1.0.0
     *
     * @param  Route   $route   Route.
     * @param  string  $method  HTTP method.
     *
     * @return array<string, mixed>
     */
    protected function operation( Route $route, string $method ): array
    {
        $middleware = $route->gatherMiddleware();
        $action     = $this->actionMethod( $route );
        $meta       = $this->attribute( $action );
        $ability    = $this->ability( $middleware );
        $policy     = $this->rateLimitPolicy( $middleware );
        $idempotent = in_array( 'ecommerce.idempotency', $middleware, true );
        $auth       = null !== $ability || [] !== array_filter( $middleware, static fn ( string $m ): bool => str_starts_with( $m, 'auth:' ) || 'auth' === $m );
        $name       = (string) $route->getName();

        $operation = [
            'operationId' => Str::camel( str_replace( [ '.', '-' ], '_', Str::after( $name, str_starts_with( $name, self::ROUTE_PREFIX ) ? self::ROUTE_PREFIX : 'ecommerce.' ) ) ),
            'summary'     => $meta?->summary ?? Str::headline( Str::afterLast( $name, '.' ) ),
            'description' => $this->operationDescription( $meta, $ability, $policy, $idempotent ),
            'tags'        => [ $this->tag( $name ) ],
            'security'    => $auth ? [ [ 'sanctum' => [] ], [ 'serviceSignature' => [] ], [ 'sessionCookie' => [] ] ] : [],
            'parameters'  => $this->operationParameters( $route, $action, $idempotent ),
            'requestBody' => $this->requestBody( $action, $method, $route ),
            'responses'   => $this->responses( $route, $meta, $auth, $policy, $idempotent, $action ),
        ];

        if ( null === $operation['requestBody'] ) {
            unset( $operation['requestBody'] );
        }

        $operation['x-rate-limit-policy'] = $policy;
        $operation['x-idempotency']       = $idempotent ? 'required' : 'none';

        if ( null !== $ability ) {
            $operation['x-ecommerce-ability'] = sprintf( 'ecommerce.%s.%s', $ability[0], $ability[1] );
            $operation['x-token-scopes']      = [ TokenAbilities::ADMIN, TokenAbilities::forAction( $ability[0], $ability[1] ) ];
        }

        return $operation;
    }

    /**
     * Human description: the attribute's text plus auth, rate-limit, and
     * idempotency notes.
     *
     * @since 1.0.0
     *
     * @param  ApiOperation|null                  $meta        Attribute.
     * @param  array{0: string, 1: string}|null  $ability     Required ability.
     * @param  string|null                        $policy      Rate-limit policy.
     * @param  bool                               $idempotent  Idempotency-Key required.
     *
     * @return string
     */
    protected function operationDescription( ?ApiOperation $meta, ?array $ability, ?string $policy, bool $idempotent ): string
    {
        $lines = array_filter( [ $meta?->description ] );

        if ( null !== $ability ) {
            $lines[] = sprintf(
                'Requires the `ecommerce.%1$s.%2$s` ability. Sanctum tokens need `%3$s` or `%4$s`.',
                $ability[0],
                $ability[1],
                TokenAbilities::ADMIN,
                TokenAbilities::forAction( $ability[0], $ability[1] ),
            );
        }

        if ( null !== $policy ) {
            $lines[] = sprintf( 'Rate-limit policy: `%s`.', $policy );
        }

        if ( $idempotent ) {
            $lines[] = 'Requires an `Idempotency-Key` header; retries with the same key replay the original response.';
        }

        return implode( "\n\n", $lines );
    }

    /**
     * Path parameters plus the Idempotency-Key header.
     *
     * @since 1.0.0
     *
     * @param  Route                  $route       Route.
     * @param  ReflectionMethod|null  $action      Controller action.
     * @param  bool                   $idempotent  Idempotency-Key required.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function operationParameters( Route $route, ?ReflectionMethod $action, bool $idempotent ): array
    {
        $parameters = [];

        foreach ( $route->parameterNames() as $name ) {
            $parameters[] = [
                'name'     => $name,
                'in'       => 'path',
                'required' => true,
                'schema'   => $this->pathParameterSchema( $route, $action, $name ),
            ];
        }

        if ( $idempotent ) {
            $parameters[] = [ '$ref' => '#/components/parameters/IdempotencyKey' ];
        }

        return $parameters;
    }

    /**
     * Schema for one path parameter, from the route constraint or the
     * action's parameter type (model bindings are integer ids).
     *
     * @since 1.0.0
     *
     * @param  Route                  $route   Route.
     * @param  ReflectionMethod|null  $action  Controller action.
     * @param  string                 $name    Parameter name.
     *
     * @return array<string, mixed>
     */
    protected function pathParameterSchema( Route $route, ?ReflectionMethod $action, string $name ): array
    {
        $pattern = $route->wheres[ $name ] ?? null;

        foreach ( $action?->getParameters() ?? [] as $parameter ) {
            if ( $parameter->getName() !== $name || ! $parameter->getType() instanceof ReflectionNamedType ) {
                continue;
            }

            $type = $parameter->getType()->getName();

            if ( 'int' === $type || is_subclass_of( $type, Model::class ) ) {
                return [ 'type' => 'integer', 'minimum' => 1 ];
            }
        }

        return null === $pattern ? [ 'type' => 'string' ] : [ 'type' => 'string', 'pattern' => '^' . $pattern . '$' ];
    }

    /**
     * Request body from the action's FormRequest rules.
     *
     * @since 1.0.0
     *
     * @param  ReflectionMethod|null  $action  Controller action.
     * @param  string                 $method  HTTP method.
     * @param  Route                  $route   Route.
     *
     * @return array<string, mixed>|null
     */
    protected function requestBody( ?ReflectionMethod $action, string $method, Route $route ): ?array
    {
        $rules = $this->formRequestRules( $action, $method, $route );

        if ( null === $rules ) {
            return null;
        }

        return [
            'required' => true,
            'content'  => [ 'application/json' => [ 'schema' => RuleSchema::fromRules( $rules ) ] ],
        ];
    }

    /**
     * The action's FormRequest rules, if it takes one.
     *
     * @since 1.0.0
     *
     * @param  ReflectionMethod|null  $action  Controller action.
     * @param  string                 $method  HTTP method.
     * @param  Route                  $route   Route.
     *
     * @return array<string, mixed>|null
     */
    protected function formRequestRules( ?ReflectionMethod $action, string $method, Route $route ): ?array
    {
        foreach ( $action?->getParameters() ?? [] as $parameter ) {
            $type = $parameter->getType();

            if ( ! $type instanceof ReflectionNamedType || ! is_subclass_of( $type->getName(), FormRequest::class ) ) {
                continue;
            }

            /** @var class-string<FormRequest> $class */
            $class   = $type->getName();
            $request = $class::create( '/' . ltrim( $route->uri(), '/' ), $method );
            $request->setContainer( app() );

            try {
                return (array) $request->rules();
            } catch ( Throwable ) {
                return [];
            }
        }

        return null;
    }

    /**
     * Responses: the success response from the attribute plus every
     * problem+json response the route's middleware can produce.
     *
     * @since 1.0.0
     *
     * @param  Route                  $route       Route.
     * @param  ApiOperation|null      $meta        Attribute.
     * @param  bool                   $auth        Authenticated route.
     * @param  string|null            $policy      Rate-limit policy.
     * @param  bool                   $idempotent  Idempotency-Key required.
     * @param  ReflectionMethod|null  $action      Controller action.
     *
     * @return array<string, mixed>
     */
    protected function responses( Route $route, ?ApiOperation $meta, bool $auth, ?string $policy, bool $idempotent, ?ReflectionMethod $action ): array
    {
        $status    = (string) ( $meta?->status ?? 200 );
        $type      = null === $meta?->resource ? null : ResourceSchemas::typeForResource( $meta->resource );
        $responses = [];

        if ( null === $type ) {
            $responses[ $status ] = [ 'description' => 'Success.', 'content' => [ 'application/json' => [ 'schema' => [ 'type' => 'object' ] ] ] ];
        } else {
            $ref    = [ '$ref' => '#/components/schemas/' . $type ];
            $schema = $meta->collection
                ? [
                    'type'       => 'object',
                    'required'   => [ 'data' ],
                    'properties' => [
                        'data'  => [ 'type' => 'array', 'items' => $ref ],
                        'links' => [ '$ref' => '#/components/schemas/PaginationLinks' ],
                        'meta'  => [ '$ref' => '#/components/schemas/PaginationMeta' ],
                    ],
                ]
                : [ 'type' => 'object', 'required' => [ 'data' ], 'properties' => [ 'data' => $ref ] ];

            $responses[ $status ] = [ 'description' => $meta->summary . '.', 'content' => [ 'application/json' => [ 'schema' => $schema ] ] ];
        }

        if ( $idempotent ) {
            $responses['400'] = [ '$ref' => '#/components/responses/MissingIdempotencyKey' ];
            $responses['409'] = [ '$ref' => '#/components/responses/IdempotencyConflict' ];
        }

        if ( $auth ) {
            $responses['401'] = [ '$ref' => '#/components/responses/Unauthenticated' ];
            $responses['403'] = [ '$ref' => '#/components/responses/Forbidden' ];
        }

        if ( [] !== $route->parameterNames() ) {
            $responses['404'] = [ '$ref' => '#/components/responses/NotFound' ];
        }

        if ( [] !== array_intersect( [ 'POST', 'PUT', 'PATCH', 'DELETE' ], $route->methods() ) || null !== $this->formRequestRules( $action, 'GET', $route ) ) {
            $responses['422'] = [ '$ref' => '#/components/responses/ValidationFailed' ];
        }

        if ( null !== $policy ) {
            $responses['429'] = [ '$ref' => '#/components/responses/RateLimited' ];
        }

        ksort( $responses );

        return $responses;
    }

    /**
     * The controller method behind a route.
     *
     * @since 1.0.0
     *
     * @param  Route  $route  Route.
     *
     * @return ReflectionMethod|null
     */
    protected function actionMethod( Route $route ): ?ReflectionMethod
    {
        $uses = $route->getAction( 'uses' );

        if ( ! is_string( $uses ) || ! str_contains( $uses, '@' ) ) {
            return null;
        }

        [ $class, $method ] = explode( '@', $uses, 2 );

        return method_exists( $class, $method ) ? new ReflectionMethod( $class, $method ) : null;
    }

    /**
     * The action's {@see ApiOperation}, if any.
     *
     * @since 1.0.0
     *
     * @param  ReflectionMethod|null  $action  Controller action.
     *
     * @return ApiOperation|null
     */
    protected function attribute( ?ReflectionMethod $action ): ?ApiOperation
    {
        $attribute = $action?->getAttributes( ApiOperation::class )[0] ?? null;

        return $attribute?->newInstance();
    }

    /**
     * `[ resource, action ]` from `ecommerce.can:{resource},{action}`.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $middleware  Route middleware.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function ability( array $middleware ): ?array
    {
        foreach ( $middleware as $entry ) {
            if ( str_starts_with( $entry, 'ecommerce.can:' ) ) {
                $parts = explode( ',', Str::after( $entry, 'ecommerce.can:' ), 2 );

                return [ $parts[0], $parts[1] ?? '' ];
            }
        }

        return null;
    }

    /**
     * The policy from `ecommerce.rate-limit:{policy}`.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $middleware  Route middleware.
     *
     * @return string|null
     */
    protected function rateLimitPolicy( array $middleware ): ?string
    {
        foreach ( $middleware as $entry ) {
            if ( str_starts_with( $entry, 'ecommerce.rate-limit:' ) ) {
                return Str::after( $entry, 'ecommerce.rate-limit:' );
            }
        }

        return null;
    }

    /**
     * Tag for a route name: `ecommerce.api.admin.tax-rates.store` → `Tax Rates`.
     *
     * @since 1.0.0
     *
     * @param  string  $name  Route name.
     *
     * @return string
     */
    protected function tag( string $name ): string
    {
        if ( self::WEBHOOK_ROUTE === $name ) {
            return 'Inbound Webhooks';
        }

        $segments = explode( '.', Str::after( $name, self::ROUTE_PREFIX ) );
        $segment  = 'admin' === $segments[0] && isset( $segments[1] ) ? $segments[1] : $segments[0];

        return Str::headline( $segment );
    }

    /**
     * Tag list for every tag used.
     *
     * @since 1.0.0
     *
     * @param  array<string, array<string, array<string, mixed>>>  $paths  Paths.
     *
     * @return array<int, array{name: string, description: string}>
     */
    protected function tags( array $paths ): array
    {
        $tags = [];

        foreach ( $paths as $operations ) {
            foreach ( $operations as $operation ) {
                $tags[ $operation['tags'][0] ] = true;
            }
        }

        ksort( $tags );

        return array_map( static fn ( string $name ): array => [
            'name'        => $name,
            'description' => sprintf( '%s endpoints.', $name ),
        ], array_keys( $tags ) );
    }

    /**
     * Component schemas: one per resource, plus shared shapes.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>>
     */
    protected function schemas(): array
    {
        $schemas = [
            'Money' => [
                'type'        => 'object',
                'description' => 'An amount in minor units (e.g. cents) and its ISO 4217 currency.',
                'required'    => [ 'amount', 'currency' ],
                'properties'  => [
                    'amount'   => [ 'type' => 'integer', 'format' => 'int64' ],
                    'currency' => [ 'type' => [ 'string', 'null' ], 'minLength' => 3, 'maxLength' => 3 ],
                ],
            ],
            'Problem' => [
                'type'        => 'object',
                'description' => 'RFC 7807 problem details (engine spec §11.5).',
                'required'    => [ 'type', 'title', 'status' ],
                'properties'  => [
                    'type'        => [ 'type' => 'string', 'format' => 'uri' ],
                    'title'       => [ 'type' => 'string' ],
                    'status'      => [ 'type' => 'integer' ],
                    'detail'      => [ 'type' => 'string' ],
                    'instance'    => [ 'type' => 'string' ],
                    'retry_after' => [ 'type' => 'integer' ],
                    'errors'      => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'required'   => [ 'field', 'code', 'message' ],
                            'properties' => [
                                'field'   => [ 'type' => 'string' ],
                                'code'    => [ 'type' => 'string' ],
                                'message' => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
            ],
            'PaginationLinks' => [
                'type'       => 'object',
                'properties' => [
                    'first' => [ 'type' => [ 'string', 'null' ] ],
                    'last'  => [ 'type' => [ 'string', 'null' ] ],
                    'prev'  => [ 'type' => [ 'string', 'null' ] ],
                    'next'  => [ 'type' => [ 'string', 'null' ] ],
                ],
            ],
            'PaginationMeta' => [
                'type'                 => 'object',
                'description'          => 'Cursor pagination state (`next_cursor` / `prev_cursor`); page-number fields on `search`.',
                'additionalProperties' => true,
            ],
        ];

        foreach ( ResourceSchemas::all() as $name => $definition ) {
            $properties = [];
            $required   = [];

            foreach ( $definition['fields'] as $field => $type ) {
                $properties[ $field ] = $this->typeSchema( $type );

                if ( str_ends_with( $type, '!' ) ) {
                    $required[] = $field;
                }
            }

            foreach ( $definition['relations'] as $field => [ $related, $isList ] ) {
                $ref                  = [ '$ref' => '#/components/schemas/' . $related ];
                $properties[ $field ] = $isList
                    ? [ 'type' => 'array', 'items' => $ref, 'description' => 'Present when requested with `include`.' ]
                    : [ 'oneOf' => [ $ref, [ 'type' => 'null' ] ], 'description' => 'Present when requested with `include`.' ];
            }

            $schemas[ $name ] = [
                'type'        => 'object',
                'description' => $definition['description'],
                'required'    => $required,
                'properties'  => $properties,
            ];
        }

        ksort( $schemas );

        return $schemas;
    }

    /**
     * JSON Schema for a GraphQL-notation field type.
     *
     * @since 1.0.0
     *
     * @param  string  $type  e.g. `Int!`, `[String!]`, `Money`.
     *
     * @return array<string, mixed>
     */
    protected function typeSchema( string $type ): array
    {
        $nonNull = str_ends_with( $type, '!' );
        $type    = rtrim( $type, '!' );

        if ( str_starts_with( $type, '[' ) ) {
            $schema = [ 'type' => 'array', 'items' => $this->typeSchema( substr( $type, 1, -1 ) ) ];
        } else {
            $schema = match ( $type ) {
                'ID', 'Int' => [ 'type' => 'integer' ],
                'BigInt'    => [ 'type' => 'integer', 'format' => 'int64' ],
                'Float'     => [ 'type' => 'number' ],
                'Boolean'   => [ 'type' => 'boolean' ],
                'DateTime'  => [ 'type' => 'string', 'format' => 'date-time' ],
                'JSON'      => [ 'description' => 'Any JSON value.' ],
                'String'    => [ 'type' => 'string' ],
                default     => [ '$ref' => '#/components/schemas/' . $type ],
            };
        }

        if ( $nonNull || ( ! isset( $schema['type'] ) && ! isset( $schema['$ref'] ) ) ) {
            return $schema;
        }

        if ( isset( $schema['$ref'] ) ) {
            return [ 'oneOf' => [ $schema, [ 'type' => 'null' ] ] ];
        }

        $schema['type'] = [ $schema['type'], 'null' ];

        return $schema;
    }

    /**
     * Shared problem+json responses.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>>
     */
    protected function problemResponses(): array
    {
        $problem = static fn ( string $description ): array => [
            'description' => $description,
            'content'     => [ 'application/problem+json' => [ 'schema' => [ '$ref' => '#/components/schemas/Problem' ] ] ],
        ];

        return [
            'MissingIdempotencyKey' => $problem( 'The `Idempotency-Key` header is missing or longer than 255 characters.' ),
            'IdempotencyConflict'   => $problem( 'The key was reused with a different payload, or the original request is still in flight.' ),
            'Unauthenticated'       => $problem( 'Authentication is required, or a service signature failed verification.' ),
            'Forbidden'             => $problem( 'The caller lacks the required ability or token scope.' ),
            'NotFound'              => $problem( 'The resource does not exist.' ),
            'ValidationFailed'      => $problem( 'The payload failed validation, or the operation was rejected.' ),
            'RateLimited'           => $problem( 'The rate-limit policy refused the request. See the `Retry-After` header.' ),
        ];
    }

    /**
     * Shared parameters.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>>
     */
    protected function parameters(): array
    {
        return [
            'IdempotencyKey' => [
                'name'        => 'Idempotency-Key',
                'in'          => 'header',
                'required'    => true,
                'description' => 'Client-generated unique key (e.g. a UUID). Retries with the same key and payload replay the original response with `Idempotent-Replay: true`.',
                'schema'      => [ 'type' => 'string', 'maxLength' => 255 ],
            ],
        ];
    }

    /**
     * Security schemes (parent plan §12.1: token, cookie session, signed service).
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>>
     */
    protected function securitySchemes(): array
    {
        return [
            'sanctum' => [
                'type'        => 'http',
                'scheme'      => 'bearer',
                'description' => sprintf(
                    'Laravel Sanctum personal access token. Token abilities narrow access: `%s` (admin API), `%s` (shopper surfaces), or per-resource scopes such as `%s`.',
                    TokenAbilities::ADMIN,
                    TokenAbilities::STOREFRONT,
                    TokenAbilities::scope( 'order', 'read' ),
                ),
            ],
            'sessionCookie' => [
                'type'        => 'apiKey',
                'in'          => 'cookie',
                'name'        => (string) config( 'session.cookie', 'laravel_session' ),
                'description' => 'Sanctum SPA cookie session for first-party storefronts.',
            ],
            'serviceSignature' => [
                'type'        => 'apiKey',
                'in'          => 'header',
                'name'        => 'Authorization',
                'description' => 'Service-to-service HMAC signature: `Signature keyId="{service}",algorithm="hmac-sha256",headers="(request-target) host date digest",signature="{base64}"`, with `Date` and `Digest: SHA-256=…` headers (engine spec §11.4).',
            ],
        ];
    }

    /**
     * Outbound webhook events (engine spec §8), as OpenAPI 3.1 `webhooks`.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>>
     */
    protected function webhooks(): array
    {
        $webhooks = [];

        foreach ( (array) config( 'artisanpack.ecommerce.webhooks.events', [] ) as $event ) {
            if ( ! is_string( $event ) ) {
                continue;
            }

            $name = WebhookPayloadFactory::eventName( $event );

            $webhooks[ $name ] = [
                'post' => [
                    'operationId' => Str::camel( 'webhook_' . str_replace( '.', '_', $name ) ),
                    'summary'     => sprintf( '`%s` event', $name ),
                    'description' => sprintf(
                        'Sent to subscriptions listening for `%1$s` (or `*`). Verify `%2$s: t=<ts>, v1=<hex hmac_sha256(ts + "." + body, secret)>` against the raw body and reject timestamps older than 5 minutes. Non-2xx responses are retried with exponential backoff; repeated failures disable the subscription.',
                        $name,
                        WebhookSigner::HEADER,
                    ),
                    'parameters'  => [
                        [ 'name' => WebhookSigner::HEADER, 'in' => 'header', 'required' => true, 'schema' => [ 'type' => 'string' ] ],
                        [ 'name' => 'X-ArtisanPack-Event', 'in' => 'header', 'required' => true, 'schema' => [ 'type' => 'string', 'const' => $name ] ],
                        [ 'name' => 'X-ArtisanPack-Delivery', 'in' => 'header', 'required' => true, 'schema' => [ 'type' => 'string' ] ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content'  => [
                            'application/json' => [
                                'schema' => [
                                    'type'       => 'object',
                                    'required'   => [ 'id', 'event', 'created_at', 'data' ],
                                    'properties' => [
                                        'id'         => [ 'type' => 'string', 'format' => 'uuid' ],
                                        'event'      => [ 'type' => 'string', 'const' => $name ],
                                        'created_at' => [ 'type' => 'string', 'format' => 'date-time' ],
                                        'data'       => [ 'type' => 'object', 'description' => sprintf( 'The public properties of `%s`, with models rendered as their REST resources.', class_basename( $event ) ) ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses'   => [ '2XX' => [ 'description' => 'Delivery accepted.' ] ],
                ],
            ];
        }

        ksort( $webhooks );

        return $webhooks;
    }

    /**
     * Document-level description.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function description(): string
    {
        return implode( "\n\n", [
            'REST API for the ArtisanPack UI ecommerce engine. Resources are JSON objects with an `id`, a `type`, their own fields, and any relations requested with `include`. Money is `{ amount, currency }` in minor units.',
            'Every mutating endpoint requires an `Idempotency-Key` header. Every endpoint names its rate-limit policy in `x-rate-limit-policy`. Errors are `application/problem+json`.',
            'A GraphQL schema with the same resources is served at `/graphql/ecommerce`.',
        ] );
    }

    /**
     * Package version from `composer.json`.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function version(): string
    {
        $path     = dirname( __DIR__, 2 ) . '/composer.json';
        $composer = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;

        return is_array( $composer ) && isset( $composer['version'] ) ? (string) $composer['version'] : '1.0.0';
    }
}
