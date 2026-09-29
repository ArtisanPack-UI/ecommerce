<?php

/**
 * AuthenticateOptionally middleware.
 *
 * For endpoints that serve both public and authenticated callers — the
 * GraphQL endpoint, where one request can mix catalog reads and admin
 * fields — resolve the caller through the Sanctum guard when credentials
 * are present, without rejecting anonymous requests. Downstream code
 * (idempotency actor scopes, rate-limit buckets, resolvers) then sees the
 * same user `$request->user()` would return behind `auth:sanctum`.
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

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class AuthenticateOptionally
{
    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  Closure  $next     Next middleware.
     *
     * @return Response
     */
    public function handle( Request $request, Closure $next ): Response
    {
        if ( null === $request->user() && null !== config( 'auth.guards.sanctum' ) ) {
            $user = Auth::guard( 'sanctum' )->user();

            if ( null !== $user ) {
                Auth::shouldUse( 'sanctum' );
                $request->setUserResolver( static fn () => $user );
            }
        }

        return $next( $request );
    }
}
