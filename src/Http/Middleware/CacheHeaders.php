<?php

/**
 * CacheHeaders middleware.
 *
 * HTTP caching for the REST API (audit F16), as `ecommerce.cache:{mode}`:
 *
 * - `public` — catalog reads: a successful GET gets a weak ETag over its
 *   body and `Cache-Control: public, max-age=…` (`api.catalog_max_age`,
 *   default 60s); a request whose `If-None-Match` matches gets a bodiless
 *   304. Signed-in requests are marked `private` instead, since their body
 *   can depend on the user.
 * - `private` — carts and checkout: `Cache-Control: private, no-store`.
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
class CacheHeaders
{
    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  Closure  $next     Next.
     * @param  string   $mode     `public` or `private`.
     *
     * @return Response
     */
    public function handle( Request $request, Closure $next, string $mode = 'public' ): Response
    {
        $response = $next( $request );

        if ( 'private' === $mode ) {
            $response->headers->set( 'Cache-Control', 'private, no-store' );

            return $response;
        }

        if ( ! $request->isMethodCacheable() || 200 !== $response->getStatusCode() ) {
            return $response;
        }

        $content = (string) $response->getContent();
        $etag    = 'W/"' . hash( 'xxh128', $content ) . '"';
        $maxAge  = max( 0, (int) config( 'artisanpack.ecommerce.api.catalog_max_age', 60 ) );

        $response->headers->set( 'ETag', $etag );
        $response->headers->set( 'Cache-Control', ( null === $request->user() ? 'public' : 'private' ) . ', max-age=' . $maxAge );
        $response->headers->set( 'Vary', 'Accept, Accept-Language, Authorization' );

        $requested = array_map( 'trim', explode( ',', (string) $request->headers->get( 'If-None-Match', '' ) ) );

        if ( in_array( $etag, $requested, true ) || in_array( '*', $requested, true ) ) {
            $response->setNotModified();
        }

        return $response;
    }
}
