<?php

/**
 * MergeGuestCartOnLogin.
 *
 * On `Illuminate\Auth\Events\Login`, merges the guest cart named by the
 * shared guest-cart cookie into the shopper's account cart (parent plan
 * §7.1) through {@see CurrentCart::mergeOnLogin()}. A currency mismatch is
 * left pending in the session for the storefront to present. Afterwards
 * the guest cookie is cleared: the signed-in shopper's cart is found
 * through their account, and an account cart's token never sits in a
 * cookie that outlives the session (unless the cart is still a guest cart,
 * when no customer record could be made for the user).
 *
 * Off when `cart.merge_on_login` is false. A failure is logged and never
 * interrupts the login.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Listeners;

use ArtisanPackUI\Ecommerce\Services\CurrentCart;
use ArtisanPackUI\Ecommerce\Support\GuestCartCookie;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class MergeGuestCartOnLogin
{
    /**
     * @since 1.0.0
     *
     * @param  CurrentCart  $currentCart  Merge runner.
     */
    public function __construct(
        protected CurrentCart $currentCart,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @param  Login  $event  Login event.
     *
     * @return void
     */
    public function handle( Login $event ): void
    {
        if ( ! (bool) config( 'artisanpack.ecommerce.cart.merge_on_login', true ) ) {
            return;
        }

        /** @var Request $request */
        $request = app( 'request' );
        $token   = GuestCartCookie::tokenFrom( $request );

        if ( null === $token ) {
            return;
        }

        try {
            $cart = $this->currentCart->mergeOnLogin( $token, $event->user, $request->hasSession() ? $request->session() : null );

            // A cart still without an account (no customer record could be
            // made for the user) stays reachable through the cookie.
            if ( null === $cart || null !== $cart->customer_id ) {
                GuestCartCookie::forget();
            }
        } catch ( Throwable $exception ) {
            Log::channel( 'ecommerce' )->warning( 'The guest cart could not be merged at login.', [
                'user_id' => $event->user->getAuthIdentifier(),
                'error'   => $exception->getMessage(),
            ] );
        }
    }
}
