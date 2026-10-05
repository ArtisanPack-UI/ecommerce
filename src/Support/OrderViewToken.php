<?php

/**
 * OrderViewToken.
 *
 * Signed, expiring links that show a guest their order without signing in
 * (#175): `{order id}-{expiry}-{HMAC}`, keyed by the app key. Confirmation
 * emails for guest orders carry one as `Order.view_url`.
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

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class OrderViewToken
{
    /**
     * A token for `$order`, valid until `$expires` (default:
     * `checkout.order_view_ttl_days` from now).
     *
     * @since 1.0.0
     *
     * @param  Order                   $order    Order.
     * @param  DateTimeInterface|null  $expires  Expiry.
     *
     * @return string
     */
    public static function for( Order $order, ?DateTimeInterface $expires = null ): string
    {
        $expires ??= Carbon::now()->addDays( max( 1, (int) config( 'artisanpack.ecommerce.checkout.order_view_ttl_days', 90 ) ) );
        $payload   = (int) $order->id . '-' . $expires->getTimestamp();

        return $payload . '-' . self::sign( $payload );
    }

    /**
     * The order `$token` shows, or null when it is malformed, tampered
     * with, expired, or for an order that was anonymized.
     *
     * @since 1.0.0
     *
     * @param  string  $token  Token.
     *
     * @return Order|null
     */
    public static function verify( string $token ): ?Order
    {
        if ( 1 !== preg_match( '/^(\d+)-(\d+)-([a-f0-9]{64})$/', $token, $parts ) ) {
            return null;
        }

        if ( ! hash_equals( self::sign( $parts[1] . '-' . $parts[2] ), $parts[3] ) || (int) $parts[2] < Carbon::now()->getTimestamp() ) {
            return null;
        }

        $order = Order::query()->find( (int) $parts[1] );

        return null === $order || CustomerService::ANONYMIZED_EMAIL === $order->email ? null : $order;
    }

    /**
     * The link a guest follows: `checkout.order_view_url` with `{token}`
     * replaced (a storefront page), else the REST endpoint.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return string
     */
    public static function url( Order $order ): string
    {
        $token    = self::for( $order );
        $template = config( 'artisanpack.ecommerce.checkout.order_view_url' );

        return is_string( $template ) && str_contains( $template, '{token}' )
            ? str_replace( '{token}', $token, $template )
            : route( 'ecommerce.api.order-views.show', [ 'token' => $token ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $payload  Signed part.
     *
     * @return string
     */
    private static function sign( string $payload ): string
    {
        return hash_hmac( 'sha256', 'order-view|' . $payload, (string) config( 'app.key' ) );
    }
}
