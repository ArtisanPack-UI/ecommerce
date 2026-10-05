<?php

/**
 * CurrentCart.
 *
 * Answers "which cart is this shopper's" for every storefront (engine spec
 * §3.13, §6.1; parent plan §7.1):
 *
 * - a signed-in shopper gets their customer's open cart; when they have
 *   none, the guest cart from their token is attached to their account;
 * - a guest gets the open cart their token names, as long as it doesn't
 *   belong to an account (a token left in a shared browser after sign-out
 *   doesn't open the account's cart);
 * - with `$create`, a cart is created when none is found.
 *
 * It also runs the login merge: {@see self::mergeGuestCart()} merges the
 * guest cart into the account cart, and a currency mismatch is kept as a
 * {@see PendingCartMerge} in the session until the shopper picks a
 * {@see CartMergeResolution} ({@see self::resolvePendingMerge()}).
 *
 * Carts are looked up through the bound {@see CartStorage}.
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

use ArtisanPackUI\Ecommerce\Contracts\CartStorage;
use ArtisanPackUI\Ecommerce\Exceptions\CartCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\ValueObjects\CartMergeResolution;
use ArtisanPackUI\Ecommerce\ValueObjects\PendingCartMerge;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CurrentCart
{
    /**
     * @since 1.0.0
     *
     * @param  CartStorage            $storage    Cart lookups.
     * @param  StorefrontCartService  $carts      Cart creation and customer attach.
     * @param  CustomerService        $customers  Customer for a signed-in user.
     * @param  CartMergeService       $merges     Guest → account merge.
     */
    public function __construct(
        protected CartStorage $storage,
        protected StorefrontCartService $carts,
        protected CustomerService $customers,
        protected CartMergeService $merges,
    ) {
    }

    /**
     * The shopper's cart.
     *
     * @since 1.0.0
     *
     * @param  string|null           $guestToken  Cart token the shopper holds (cookie, header, client storage).
     * @param  Authenticatable|null  $user        Signed-in user.
     * @param  bool                  $create      Create a cart when none is found.
     * @param  string|null           $currency    Currency for a created cart (defaults to the base currency).
     *
     * @return Cart|null
     */
    public function resolve( ?string $guestToken, ?Authenticatable $user, bool $create = false, ?string $currency = null ): ?Cart
    {
        $guest = $this->guestCart( $guestToken );

        if ( null === $user ) {
            return $guest ?? ( $create ? $this->carts->create( $currency ) : null );
        }

        $customer = $this->customers->customerForUser( $user, $create || null !== $guest );

        if ( null === $customer ) {
            // No account record (an unverified email already in use): the
            // shopper keeps shopping with their guest cart.
            return $guest ?? ( $create ? $this->carts->create( $currency ) : null );
        }

        $account = $this->accountCart( $customer );

        if ( null !== $account ) {
            return $account;
        }

        if ( null !== $guest ) {
            return $this->carts->attachCustomer( $guest, $customer );
        }

        return $create ? $this->carts->create( $currency, $customer->email, $customer ) : null;
    }

    /**
     * The open cart `$token` names, when it isn't an account's.
     *
     * @since 1.0.0
     *
     * @param  string|null  $token  Cart token.
     *
     * @return Cart|null
     */
    public function guestCart( ?string $token ): ?Cart
    {
        if ( null === $token || '' === $token ) {
            return null;
        }

        $cart = $this->storage->find( $token );

        return null !== $cart && null === $cart->customer_id && $this->isOpen( $cart ) ? $cart : null;
    }

    /**
     * The customer's open cart.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer.
     *
     * @return Cart|null
     */
    public function accountCart( Customer $customer ): ?Cart
    {
        $cart = $this->storage->findForCustomer( (int) $customer->id );

        return null !== $cart && $this->isOpen( $cart ) ? $cart : null;
    }

    /**
     * Merges the guest cart `$guestToken` names into `$user`'s account cart
     * (parent plan §7.1), or attaches it to their account when they have no
     * cart yet.
     *
     * @since 1.0.0
     *
     * @param  string                    $guestToken  Guest cart token.
     * @param  Authenticatable           $user        Signed-in user.
     * @param  CartMergeResolution|null  $resolution  Choice for a currency mismatch.
     *
     * @throws CartCurrencyMismatchException When the carts' currencies differ and no resolution is given.
     * @throws CartOperationException        When a cart can't take part.
     *
     * @return Cart|null The shopper's cart now, or null when there was no guest cart to merge.
     */
    public function mergeGuestCart( string $guestToken, Authenticatable $user, ?CartMergeResolution $resolution = null ): ?Cart
    {
        $guest = $this->guestCart( $guestToken );

        if ( null === $guest ) {
            return null;
        }

        $customer = $this->customers->customerForUser( $user, true );

        if ( null === $customer ) {
            return $guest;
        }

        $account = $this->accountCart( $customer );

        if ( null === $account ) {
            return $this->carts->attachCustomer( $guest, $customer );
        }

        return $this->merges->merge( $guest, $account, $resolution );
    }

    /**
     * Runs the login merge and records a currency mismatch in `$session`
     * for the storefront to resolve. Clears any earlier pending merge.
     *
     * @since 1.0.0
     *
     * @param  string           $guestToken  Guest cart token.
     * @param  Authenticatable  $user        Signed-in user.
     * @param  Session|null     $session     Session to record a pending merge in.
     *
     * @throws CartOperationException When a cart can't take part.
     *
     * @return Cart|null The shopper's cart now (the account cart while a merge is pending), or null when there was nothing to merge.
     */
    public function mergeOnLogin( string $guestToken, Authenticatable $user, ?Session $session = null ): ?Cart
    {
        $session?->forget( PendingCartMerge::SESSION_KEY );

        try {
            return $this->mergeGuestCart( $guestToken, $user );
        } catch ( CartCurrencyMismatchException $exception ) {
            $session?->put( PendingCartMerge::SESSION_KEY, PendingCartMerge::fromException( $exception )->toArray() );

            return $exception->destinationCart;
        }
    }

    /**
     * The merge waiting on the shopper, if any.
     *
     * @since 1.0.0
     *
     * @param  Session  $session  Session.
     *
     * @return PendingCartMerge|null
     */
    public function pendingMerge( Session $session ): ?PendingCartMerge
    {
        return PendingCartMerge::fromArray( $session->get( PendingCartMerge::SESSION_KEY ) );
    }

    /**
     * Applies the shopper's choice to the pending merge and clears it.
     *
     * @since 1.0.0
     *
     * @param  Session              $session     Session holding the pending merge.
     * @param  Authenticatable      $user        Signed-in user.
     * @param  CartMergeResolution  $resolution  Shopper's choice.
     *
     * @throws CartOperationException When a cart can't take part.
     *
     * @return Cart|null The shopper's cart, or null when nothing was pending (or the guest cart is gone).
     */
    public function resolvePendingMerge( Session $session, Authenticatable $user, CartMergeResolution $resolution ): ?Cart
    {
        $pending = $this->pendingMerge( $session );

        $session->forget( PendingCartMerge::SESSION_KEY );

        if ( null === $pending ) {
            return null;
        }

        return $this->mergeGuestCart( $pending->guestToken, $user, $resolution );
    }

    /**
     * Whether a cart can still be shopped with.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return bool
     */
    protected function isOpen( Cart $cart ): bool
    {
        return null === $cart->completed_order_id && ( null === $cart->expires_at || $cart->expires_at->isFuture() );
    }
}
