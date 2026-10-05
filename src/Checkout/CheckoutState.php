<?php

/**
 * CheckoutState.
 *
 * The checkout states a cart moves through (parent plan §7.4), kept in
 * `carts.checkout_state`:
 *
 * `not_started` → `addressing` → `shipping_selection` → `payment_selection`
 * → `payment_pending` → `payment_confirmed` → `completed`, with `failed`
 * reachable from the payment states.
 *
 * The engine doesn't force a step order beyond what a step needs (an
 * address before shipping can be chosen, a gateway before a payment session
 * is created), so one-page, multi-step, and express checkouts all fit.
 * Every transition runs the `ap.ecommerce.checkout.canTransitionTo` filter.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Checkout;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class CheckoutState
{
    /**
     * Checkout hasn't started.
     *
     * @since 1.0.0
     */
    public const NOT_STARTED = 'not_started';

    /**
     * Collecting the email and addresses.
     *
     * @since 1.0.0
     */
    public const ADDRESSING = 'addressing';

    /**
     * Addresses known; choosing a shipping rate.
     *
     * @since 1.0.0
     */
    public const SHIPPING_SELECTION = 'shipping_selection';

    /**
     * Shipping settled; choosing a payment gateway.
     *
     * @since 1.0.0
     */
    public const PAYMENT_SELECTION = 'payment_selection';

    /**
     * A payment session exists and awaits the shopper's confirmation.
     *
     * @since 1.0.0
     */
    public const PAYMENT_PENDING = 'payment_pending';

    /**
     * The shopper confirmed the payment; the order is being placed.
     *
     * @since 1.0.0
     */
    public const PAYMENT_CONFIRMED = 'payment_confirmed';

    /**
     * The cart became an order.
     *
     * @since 1.0.0
     */
    public const COMPLETED = 'completed';

    /**
     * The payment failed terminally (blocked, or the session died).
     *
     * @since 1.0.0
     */
    public const FAILED = 'failed';

    /**
     * Every state, in checkout order.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const ORDER = [
        self::NOT_STARTED,
        self::ADDRESSING,
        self::SHIPPING_SELECTION,
        self::PAYMENT_SELECTION,
        self::PAYMENT_PENDING,
        self::PAYMENT_CONFIRMED,
        self::COMPLETED,
        self::FAILED,
    ];

    /**
     * Position of `$state` in {@see self::ORDER} (failed sorts last).
     *
     * @since 1.0.0
     *
     * @param  string  $state  State.
     *
     * @return int
     */
    public static function rank( string $state ): int
    {
        $index = array_search( $state, self::ORDER, true );

        return false === $index ? 0 : (int) $index;
    }

    /**
     * Whether `$state` is past `$than` in checkout order.
     *
     * @since 1.0.0
     *
     * @param  string  $state  State.
     * @param  string  $than   Reference state.
     *
     * @return bool
     */
    public static function isAfter( string $state, string $than ): bool
    {
        return self::rank( $state ) > self::rank( $than );
    }
}
