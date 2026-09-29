<?php

/**
 * ForceJsonResponse middleware.
 *
 * Marks every `/api/ecommerce/v1` request as expecting JSON, so framework
 * responses (401s, 404s, validation) are JSON rather than HTML redirects —
 * without it an unauthenticated browser request tries to redirect to a
 * `login` route the host may not have and fails with a 500.
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
use Symfony\Component\HttpFoundation\Response;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ForceJsonResponse
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
        $request->headers->set( 'Accept', 'application/json' );

        return $next( $request );
    }
}
