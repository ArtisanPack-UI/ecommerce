<?php

/**
 * OrderPlacementException.
 *
 * Raised by {@see \ArtisanPackUI\Ecommerce\Services\OrderPlacementService}
 * when a cart can't become an order. Nothing is written: placement runs in
 * one transaction. Each reason has its own named constructor and code.
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

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderPlacementException extends CheckoutException
{
    /**
     * The cart has no lines.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function emptyCart(): self
    {
        return new self( 'cart', 'cart-empty', __( 'Your cart is empty.' ) );
    }

    /**
     * The cart already became an order, or expired.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function cartClosed(): self
    {
        return new self( 'cart', 'cart-closed', __( 'This cart can no longer be changed.' ), 409 );
    }

    /**
     * Some lines can no longer be sold.
     *
     * @since 1.0.0
     *
     * @param  array<int, int>  $itemIds  Cart item ids.
     *
     * @return self
     */
    public static function unsellableItems( array $itemIds ): self
    {
        return new self( 'items', 'items-unavailable', trans_choice( 'An item in your cart is no longer available.|Some items in your cart are no longer available.', count( $itemIds ) ) );
    }

    /**
     * A variable product line has no variant.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function variantRequired(): self
    {
        return new self( 'items', 'variant-required', __( 'Choose the options for every item in your cart.' ) );
    }

    /**
     * Not enough stock for a line.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function insufficientStock(): self
    {
        return new self( 'items', 'insufficient-stock', __( 'Some items in your cart are no longer in stock in the quantity you chose.' ), 409 );
    }

    /**
     * The order needs a shipping address.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function shippingAddressRequired(): self
    {
        return new self( 'shipping_address', 'shipping-address-required', __( 'Enter a shipping address.' ) );
    }

    /**
     * The order needs a billing address.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function billingAddressRequired(): self
    {
        return new self( 'billing_address', 'billing-address-required', __( 'Enter a billing address.' ) );
    }

    /**
     * The order needs an email.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function emailRequired(): self
    {
        return new self( 'email', 'email-required', __( 'Enter your email address.' ) );
    }

    /**
     * No shipping rate is chosen, or the chosen one is no longer offered.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function shippingRateStale(): self
    {
        return new self( 'shipping_rate', 'shipping-rate-required', __( 'Choose a shipping option.' ) );
    }

    /**
     * The coupon on the cart no longer applies.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function couponNoLongerValid(): self
    {
        return new self( 'coupon', 'coupon-no-longer-valid', __( 'Your coupon no longer applies, so your total changed. Review your cart before placing the order.' ), 409 );
    }

    /**
     * The totals changed since the payment was set up.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function totalsChanged(): self
    {
        return new self( 'cart', 'totals-changed', __( 'Your order total changed. Review your cart and confirm the payment again.' ), 409 );
    }

    /**
     * No exchange rate is available for the order currency.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function exchangeRateUnavailable(): self
    {
        return new self( 'currency', 'exchange-rate-unavailable', __( 'Orders in this currency can\'t be placed right now. Try again shortly.' ), 503 );
    }
}
