<?php

/**
 * ReturnUrl.
 *
 * Checks a URL a payment provider will send the shopper back to (after a
 * redirect or a 3DS step), so the checkout endpoints can't be used as an
 * open redirect: it must be on this site — the request's host or the host
 * of `app.url`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ReturnUrl
{
    /**
     * Whether `$url` is an http(s) URL on this site.
     *
     * @since 1.0.0
     *
     * @param  string   $url      URL.
     * @param  Request  $request  Current request.
     *
     * @return bool
     */
    public static function isAllowed( string $url, Request $request ): bool
    {
        $scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
        $host   = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
            return false;
        }

        $allowed = array_map( 'strtolower', array_filter( [ $request->getHost(), (string) parse_url( (string) config( 'app.url' ), PHP_URL_HOST ) ] ) );

        return in_array( $host, $allowed, true );
    }
}
