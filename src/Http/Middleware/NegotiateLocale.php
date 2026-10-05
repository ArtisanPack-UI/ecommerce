<?php

/**
 * NegotiateLocale middleware.
 *
 * `ecommerce.locale` (audit H2): when a request sends `Accept-Language`,
 * picks the best of `localization.supported_locales` and makes it the app
 * locale for the request, so problem details, validation messages, and
 * resource labels come back in the shopper's language, and new carts record
 * it for later notifications. Responses say which language they're in
 * (`Content-Language`) and that they depend on the header
 * (`Vary: Accept-Language`). Requests without the header keep the host's
 * locale. The previous locale is restored afterwards, for long-lived
 * workers.
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

use ArtisanPackUI\Ecommerce\Support\SupportedLocales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NegotiateLocale
{
    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  Closure  $next     Next.
     *
     * @return Response
     */
    public function handle( Request $request, Closure $next ): Response
    {
        $previous = app()->getLocale();
        $locale   = self::negotiate( $request );

        if ( null !== $locale ) {
            app()->setLocale( $locale );
        }

        try {
            $response = $next( $request );
        } finally {
            app()->setLocale( $previous );
        }

        $response->headers->set( 'Content-Language', str_replace( '_', '-', $locale ?? $previous ) );
        $response->setVary( 'Accept-Language', false );

        return $response;
    }

    /**
     * The supported locale `$request` asks for, or null when it sends no
     * `Accept-Language`.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return string|null
     */
    public static function negotiate( Request $request ): ?string
    {
        if ( '' === trim( (string) $request->headers->get( 'Accept-Language', '' ) ) ) {
            return null;
        }

        $supported = SupportedLocales::all();

        return [] === $supported ? null : $request->getPreferredLanguage( $supported );
    }
}
