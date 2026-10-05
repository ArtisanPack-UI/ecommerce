<?php

/**
 * EnsureEcommerceAbility middleware.
 *
 * Gates REST routes on an engine ability (`->middleware('ecommerce.can:order,viewAny')`).
 * The decision is delegated to {@see EcommerceAuthorizer} (default-deny:
 * Gate ability → umbrella `ecommerce.admin` → deny, then the
 * `ap.ecommerce.abilities.{resource}.{action}` filter, then the caller's
 * Sanctum token abilities), the same decision the policies and GraphQL
 * resolvers use. Unauthenticated requests get 401, denied ones 403, both
 * as `problem+json`. On success the request is flagged `ecommerce.admin`
 * so resources can reveal admin-only fields.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Middleware;

use ArtisanPackUI\Ecommerce\Auth\EcommerceAuthorizer;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class EnsureEcommerceAbility
{
    /**
     * Request attribute set when an ability check passes.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ADMIN_ATTRIBUTE = 'ecommerce.admin';

    /**
     * @since 1.0.0
     *
     * @param  EcommerceAuthorizer  $authorizer  Shared ability decision.
     */
    public function __construct( private readonly EcommerceAuthorizer $authorizer )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request   Request.
     * @param  Closure  $next      Next middleware.
     * @param  string   $resource  Resource name (e.g. `order`).
     * @param  string   $action    Action name (e.g. `viewAny`).
     *
     * @return Response
     */
    public function handle( Request $request, Closure $next, string $resource, string $action ): Response
    {
        $user = $request->user();

        if ( null === $user ) {
            return Problem::make( 401, 'unauthenticated', __( 'Unauthenticated' ), __( 'Authentication is required.' ), $request );
        }

        // This runs before SubstituteBindings (auth sorts ahead of it), so
        // bind the route's models now: the gate gets the model it guards.
        $route = $request->route();

        if ( $route instanceof Route ) {
            $router = app( Router::class );

            // A missing model isn't reported here: the ability is checked
            // first (so ids can't be probed), and the binding pass after
            // this middleware answers the 404.
            try {
                $router->substituteBindings( $route );
                $router->substituteImplicitBindings( $route );
            } catch ( ModelNotFoundException | BackedEnumCaseNotFoundException ) {
                // Leave the parameters unbound.
            }
        }

        $ability = sprintf( 'ecommerce.%s.%s', $resource, $action );
        $allowed = $this->authorizer->allows( $user, $resource, $action, EcommerceAuthorizer::routeSubject( $request ), $request );

        if ( ! $allowed ) {
            return Problem::make( 403, 'forbidden', __( 'Forbidden' ), __( 'Missing ability :ability.', [ 'ability' => $ability ] ), $request );
        }

        $request->attributes->set( self::ADMIN_ATTRIBUTE, true );

        return $next( $request );
    }
}
