<?php

/**
 * GuestOrderLookupService.
 *
 * Finds an order from its number and the email it was placed with, for
 * guests (#175). Emails compare in constant time and "no such order" looks
 * exactly like "wrong email". Failures count against the order number and
 * the caller's address; past `checkout.guest_lookup.*` they lock out for
 * `lockout_minutes`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Exceptions\GuestLookupLockedException;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Support\Facades\RateLimiter;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class GuestOrderLookupService
{
    /**
     * The order `$orderNumber` placed with `$email`, or null.
     *
     * @since 1.0.0
     *
     * @param  string       $email        Email as typed.
     * @param  string       $orderNumber  Order number as typed.
     * @param  string|null  $ip           Caller address, for the lockout.
     *
     * @throws GuestLookupLockedException When too many lookups failed.
     *
     * @return Order|null
     */
    public function find( string $email, string $orderNumber, ?string $ip = null ): ?Order
    {
        $orderNumber = trim( $orderNumber );
        $keys        = [
            'ecommerce:lookup:order:' . sha1( $orderNumber )          => $this->limit( 'max_failures_per_order', 5 ),
            'ecommerce:lookup:ip:' . sha1( (string) ( $ip ?? '' ) )   => $this->limit( 'max_failures_per_ip', 20 ),
        ];

        foreach ( $keys as $key => $limit ) {
            if ( RateLimiter::tooManyAttempts( $key, $limit ) ) {
                throw new GuestLookupLockedException( RateLimiter::availableIn( $key ) );
            }
        }

        $order    = '' === $orderNumber ? null : Order::query()->where( 'order_number', $orderNumber )->first();
        $expected = mb_strtolower( trim( (string) ( $order?->email ?? '' ) ) );
        $given    = mb_strtolower( trim( $email ) );

        // Compare even when there is no order, so timing doesn't tell.
        $matches = hash_equals( hash( 'sha256', '' === $expected ? "\0" : $expected ), hash( 'sha256', $given ) )
            && null !== $order
            && CustomerService::ANONYMIZED_EMAIL !== $order->email;

        if ( ! $matches ) {
            $decay = 60 * max( 1, (int) config( 'artisanpack.ecommerce.checkout.guest_lookup.lockout_minutes', 15 ) );

            foreach ( array_keys( $keys ) as $key ) {
                RateLimiter::hit( $key, $decay );
            }

            return null;
        }

        RateLimiter::clear( array_key_first( $keys ) );

        return $order;
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $setting  `checkout.guest_lookup` key.
     * @param  int     $default  Default.
     *
     * @return int
     */
    protected function limit( string $setting, int $default ): int
    {
        $value = config( 'artisanpack.ecommerce.checkout.guest_lookup.' . $setting );

        return is_numeric( $value ) && (int) $value > 0 ? (int) $value : $default;
    }
}
