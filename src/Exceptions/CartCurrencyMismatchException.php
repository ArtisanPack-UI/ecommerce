<?php

/**
 * CartCurrencyMismatchException.
 *
 * Thrown from {@see \ArtisanPackUI\Ecommerce\Services\CartMergeService::merge()}
 * when the guest cart and destination cart transact in different currencies
 * and the caller did not pass an explicit resolution. The engine never
 * silently re-prices on FX drift — the caller must present the mismatch to
 * the shopper (typically as a modal: keep-guest-currency, switch-to-account,
 * or cancel) and re-invoke the merge with a {@see \ArtisanPackUI\Ecommerce\ValueObjects\CartMergeResolution}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Exceptions;

use ArtisanPackUI\Ecommerce\Models\Cart;
use RuntimeException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CartCurrencyMismatchException extends RuntimeException
{
    /**
     * @since 1.0.0
     *
     * @param  Cart  $guestCart        The source (guest) cart being merged in.
     * @param  Cart  $destinationCart  The destination (customer) cart.
     */
    public function __construct(
        public readonly Cart $guestCart,
        public readonly Cart $destinationCart,
    ) {
        parent::__construct(
            sprintf(
                'Cart currency mismatch: guest cart is %s, destination cart is %s.',
                $guestCart->currency,
                $destinationCart->currency,
            ),
        );
    }

    /**
     * ISO 4217 currency code of the guest cart.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function guestCurrency(): string
    {
        return $this->guestCart->currency;
    }

    /**
     * ISO 4217 currency code of the destination cart.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function destinationCurrency(): string
    {
        return $this->destinationCart->currency;
    }
}
