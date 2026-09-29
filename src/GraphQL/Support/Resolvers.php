<?php

/**
 * Resolvers.
 *
 * Shared plumbing for the ecommerce GraphQL resolvers, so every root field
 * behaves like its REST counterpart:
 *
 * - **Auth** — the acting user comes from the request (a signed service
 *   actor, a Sanctum token, or a cookie session) and abilities are decided
 *   by {@see EcommerceAuthorizer}, the same decision REST and the policies
 *   use.
 * - **Rate limits** — each field names its REST route's rate-limit policy
 *   (engine spec §11.3), enforced through {@see RateLimitEcommerce}.
 * - **Shape** — models render through their REST resource, so a GraphQL
 *   object has exactly the REST fields and the
 *   `ap.ecommerce.api.resource.{name}` filters apply to both.
 * - **No N+1** — the relations a query selects are eager-loaded up front,
 *   from the field selection, before rendering.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\GraphQL\Support;

use ArtisanPackUI\Ecommerce\Api\ResourceSchemas;
use ArtisanPackUI\Ecommerce\Auth\EcommerceAuthorizer;
use ArtisanPackUI\Ecommerce\GraphQL\GraphQLError;
use ArtisanPackUI\Ecommerce\Http\Middleware\EnsureEcommerceAbility;
use ArtisanPackUI\Ecommerce\Http\Middleware\RateLimitEcommerce;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class Resolvers
{
    /**
     * How deep to read a field selection when planning eager loads.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const SELECTION_DEPTH = 8;

    /**
     * @since 1.0.0
     *
     * @param  EcommerceAuthorizer  $authorizer  Shared ability decision.
     */
    public function __construct( protected EcommerceAuthorizer $authorizer )
    {
    }

    /**
     * The acting user: whoever the request is authenticated as (a service
     * actor, a Sanctum token user, or a cookie-session user), if anyone.
     * {@see \ArtisanPackUI\Ecommerce\Http\Middleware\AuthenticateOptionally}
     * resolves Sanctum credentials on the GraphQL route.
     *
     * @since 1.0.0
     *
     * @return Authenticatable|null
     */
    public function user(): ?Authenticatable
    {
        return request()->user();
    }

    /**
     * The acting user, or an `UNAUTHENTICATED` error.
     *
     * @since 1.0.0
     *
     * @throws GraphQLError When nobody is authenticated.
     *
     * @return Authenticatable
     */
    public function requireUser(): Authenticatable
    {
        return $this->user() ?? throw GraphQLError::unauthenticated();
    }

    /**
     * Requires `$action` on `$resource` for the acting user.
     *
     * @since 1.0.0
     *
     * @param  string  $resource  Engine resource name.
     * @param  string  $action    Action name.
     * @param  mixed   $subject   Model being acted on, if any.
     *
     * @throws GraphQLError When unauthenticated or not allowed.
     *
     * @return Authenticatable
     */
    public function authorize( string $resource, string $action, mixed $subject = null ): Authenticatable
    {
        $user = $this->requireUser();

        if ( ! $this->authorizer->allows( $user, $resource, $action, $subject ?? request(), request() ) ) {
            throw GraphQLError::forbidden( sprintf( 'ecommerce.%s.%s', $resource, $action ) );
        }

        return $user;
    }

    /**
     * Whether the acting user may perform `$action` on `$resource`.
     *
     * @since 1.0.0
     *
     * @param  string  $resource  Engine resource name.
     * @param  string  $action    Action name.
     *
     * @return bool
     */
    public function allows( string $resource, string $action ): bool
    {
        $user = $this->user();

        return null !== $user && $this->authorizer->allows( $user, $resource, $action, request(), request() );
    }

    /**
     * Spends one request from a named rate-limit policy.
     *
     * @since 1.0.0
     *
     * @param  string                $policy  Policy name (engine spec §11.3).
     * @param  array<string, mixed>  $input   Extra input the policy keys on (e.g. `cart_token`).
     *
     * @throws GraphQLError When the caller is over the limit.
     *
     * @return void
     */
    public function throttle( string $policy, array $input = [] ): void
    {
        $request = request();

        if ( [] !== $input ) {
            $request = $request->duplicate();
            $request->merge( $input );
            $request->setUserResolver( request()->getUserResolver() );
        }

        $response = app( RateLimitEcommerce::class )->handle( $request, static fn (): Response => new Response(), $policy );

        if ( 429 === $response->getStatusCode() ) {
            throw GraphQLError::rateLimited( (int) $response->headers->get( 'Retry-After', '60' ) );
        }
    }

    /**
     * A copy of the request for rendering resources: flagged as an admin
     * request (revealing admin-only fields) only when `$admin` is true.
     * A copy, so one admin field in a query can't reveal admin fields in a
     * public field of the same query.
     *
     * @since 1.0.0
     *
     * @param  bool  $admin  Render admin-only fields.
     *
     * @return Request
     */
    public function renderRequest( bool $admin ): Request
    {
        $request = request()->duplicate();
        $request->setUserResolver( request()->getUserResolver() );
        $request->attributes->set( EnsureEcommerceAbility::ADMIN_ATTRIBUTE, $admin );

        return $request;
    }

    /**
     * Renders one model through its REST resource.
     *
     * @since 1.0.0
     *
     * @param  Model|null  $model  Model.
     * @param  bool        $admin  Render admin-only fields.
     *
     * @return array<string, mixed>|null
     */
    public function render( ?Model $model, bool $admin = false ): ?array
    {
        if ( null === $model ) {
            return null;
        }

        $resource = ResourceSchemas::resourceForModel( $model );

        return null === $resource ? $model->attributesToArray() : ( new $resource( $model ) )->resolve( $this->renderRequest( $admin ) );
    }

    /**
     * Renders many models.
     *
     * @since 1.0.0
     *
     * @param  iterable<Model>  $models  Models.
     * @param  bool             $admin   Render admin-only fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function renderMany( iterable $models, bool $admin = false ): array
    {
        $rendered = [];

        foreach ( $models as $model ) {
            $rendered[] = $this->render( $model, $admin );
        }

        return $rendered;
    }

    /**
     * Eager-loads what `$selection` asks for on `$type` and renders the model.
     *
     * @since 1.0.0
     *
     * @param  Model|null            $model      Model.
     * @param  string                $type       GraphQL type name.
     * @param  array<string, mixed>  $selection  Field selection for the type.
     * @param  bool                  $admin      Render admin-only fields.
     *
     * @return array<string, mixed>|null
     */
    public function present( ?Model $model, string $type, array $selection, bool $admin = false ): ?array
    {
        if ( null === $model ) {
            return null;
        }

        $model->load( $this->eagerLoads( $type, $selection, $admin ) );

        return $this->render( $model, $admin );
    }

    /**
     * Relation paths (with constraints) to eager-load for a selection.
     * Public requests only see price rows current right now, exactly as
     * the REST catalog endpoints do.
     *
     * @since 1.0.0
     *
     * @param  string                $type       GraphQL type name.
     * @param  array<string, mixed>  $selection  Field selection.
     * @param  bool                  $admin      Admin request.
     *
     * @return array<int, string>|array<string, Closure|null>
     */
    public function eagerLoads( string $type, array $selection, bool $admin = false ): array
    {
        $loads = [];

        foreach ( $this->relationPaths( $type, $selection, '', $admin ) as $path ) {
            if ( ! $admin && ( 'prices' === $path || str_ends_with( $path, '.prices' ) ) ) {
                $loads[ $path ] = static fn ( $query ) => $query->currentAt( Carbon::now() );

                continue;
            }

            $loads[] = $path;
        }

        return $loads;
    }

    /**
     * The Eloquent relation paths a field selection reaches. Admin-only
     * relations (see {@see ResourceSchemas}) are skipped for non-admin
     * requests, so they render as `null` rather than leaking.
     *
     * @since 1.0.0
     *
     * @param  string                $type       GraphQL type name.
     * @param  array<string, mixed>  $selection  Field selection.
     * @param  string                $prefix     Path prefix.
     * @param  bool                  $admin      Admin request.
     *
     * @return array<int, string>
     */
    public function relationPaths( string $type, array $selection, string $prefix = '', bool $admin = false ): array
    {
        $schema = ResourceSchemas::get( $type );

        if ( null === $schema ) {
            return [];
        }

        $paths = [];

        foreach ( $schema['relations'] as $field => $definition ) {
            [ $related, , $relation ] = $definition;

            if ( ! array_key_exists( $field, $selection ) || ( ! $admin && ( $definition[3] ?? false ) ) ) {
                continue;
            }

            $path    = $prefix . $relation;
            $paths[] = $path;

            if ( is_array( $selection[ $field ] ) ) {
                array_push( $paths, ...$this->relationPaths( $related, $selection[ $field ], $path . '.', $admin ) );
            }
        }

        return $paths;
    }

    /**
     * The field selection below `$info`, optionally narrowed to a sub-path
     * (e.g. `cart` in a mutation payload).
     *
     * @since 1.0.0
     *
     * @param  ResolveInfo  $info  Resolve info.
     * @param  string|null  $path  Dot path into the selection.
     *
     * @return array<string, mixed>
     */
    public function selection( ResolveInfo $info, ?string $path = null ): array
    {
        $selection = $info->getFieldSelection( self::SELECTION_DEPTH );

        foreach ( null === $path ? [] : explode( '.', $path ) as $segment ) {
            $selection = is_array( $selection[ $segment ] ?? null ) ? $selection[ $segment ] : [];
        }

        return $selection;
    }

    /**
     * Resolves a Relay connection (engine spec §10.1) over a query.
     *
     * @since 1.0.0
     *
     * @param  Builder<Model>        $query  Base query (already filtered).
     * @param  string                $type   Node type name.
     * @param  array<string, mixed>  $args   `first` / `after` arguments.
     * @param  ResolveInfo           $info   Resolve info.
     * @param  bool                  $admin  Render admin-only fields.
     *
     * @return array<string, mixed>
     */
    public function connection( Builder $query, string $type, array $args, ResolveInfo $info, bool $admin = false ): array
    {
        $selection = $info->getFieldSelection( self::SELECTION_DEPTH );
        $nodeSel   = array_replace_recursive(
            is_array( $selection['nodes'] ?? null ) ? $selection['nodes'] : [],
            is_array( $selection['edges']['node'] ?? null ) ? $selection['edges']['node'] : [],
        );

        if ( [] === $query->getQuery()->orders ) {
            $query->orderBy( $query->getModel()->getQualifiedKeyName(), 'desc' );
        }

        $max       = max( 1, (int) config( 'artisanpack.ecommerce.api.max_per_page', 100 ) );
        $first     = (int) ( $args['first'] ?? config( 'artisanpack.ecommerce.api.default_per_page', 25 ) );
        $after     = isset( $args['after'] ) ? Cursor::fromEncoded( (string) $args['after'] ) : null;
        $paginator = $query->with( $this->eagerLoads( $type, $nodeSel, $admin ) )
            ->cursorPaginate( max( 1, min( $max, $first ) ), [ '*' ], 'cursor', $after );

        $edges = [];

        foreach ( $paginator->items() as $item ) {
            $edges[] = [
                'cursor' => $paginator->getCursorForItem( $item )->encode(),
                'node'   => $this->render( $item, $admin ),
            ];
        }

        return [
            'edges'    => $edges,
            'nodes'    => array_column( $edges, 'node' ),
            'pageInfo' => [
                'hasNextPage'     => $paginator->hasMorePages(),
                'hasPreviousPage' => null !== $after,
                'startCursor'     => $edges[0]['cursor'] ?? null,
                'endCursor'       => [] === $edges ? null : $edges[ count( $edges ) - 1 ]['cursor'],
            ],
        ];
    }
}
