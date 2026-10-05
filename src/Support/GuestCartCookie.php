<?php

/**
 * GuestCartCookie.
 *
 * The cookie that carries a guest's cart token, with the name and lifetime
 * every storefront shares (`cart.cookie`, `cart.cookie_lifetime`). The
 * host's EncryptCookies middleware encrypts it like any other cookie.
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

use ArtisanPackUI\Ecommerce\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class GuestCartCookie
{
    /**
     * Shape of a cart token.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9]{40}$/';

    /**
     * The cookie name.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function name(): string
    {
        $name = (string) config( 'artisanpack.ecommerce.cart.cookie', 'ecommerce_cart' );

        return '' === $name ? 'ecommerce_cart' : $name;
    }

    /**
     * The cookie lifetime, in minutes.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public static function lifetime(): int
    {
        $minutes = (int) config( 'artisanpack.ecommerce.cart.cookie_lifetime', 43_200 );

        return $minutes > 0 ? $minutes : 43_200;
    }

    /**
     * The well-formed cart token in `$request`'s cookie, if any.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return string|null
     */
    public static function tokenFrom( Request $request ): ?string
    {
        $token = $request->cookie( self::name() );

        return is_string( $token ) && 1 === preg_match( self::TOKEN_PATTERN, $token ) ? $token : null;
    }

    /**
     * A cookie holding `$cart`'s token.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return SymfonyCookie
     */
    public static function make( Cart $cart ): SymfonyCookie
    {
        return Cookie::make( self::name(), (string) $cart->token, self::lifetime(), null, null, null, true, false, 'lax' );
    }

    /**
     * Queues the cookie for `$cart` on the current response.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return void
     */
    public static function queue( Cart $cart ): void
    {
        Cookie::queue( self::make( $cart ) );
    }

    /**
     * Queues removal of the cookie.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function forget(): void
    {
        Cookie::queue( Cookie::forget( self::name() ) );
    }
}
