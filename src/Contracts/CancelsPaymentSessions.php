<?php

/**
 * CancelsPaymentSessions contract.
 *
 * Optional companion to {@see PaymentGateway} for a provider that can
 * cancel a payment session the shopper hasn't confirmed (Stripe cancels a
 * PaymentIntent, PayPal voids an order). When
 * {@see \ArtisanPackUI\Ecommerce\Services\CheckoutService::createPaymentSession()}
 * replaces a cart's session (its total changed), or finalize drops one for
 * a changed total, the old session is cancelled first, so a checkout tab
 * still showing it can't take a payment for the old amount (#231).
 * Gateways without the contract leave the old session open; if it is
 * confirmed later, the engine still matches it through the cart's payment
 * session history and applies the `checkout.stranded_payments` policy.
 *
 * Implement it only when the provider refuses, atomically, to cancel a
 * session that became confirmed after you checked it. Stripe, for example,
 * cancels a manual-capture PaymentIntent in `requires_capture` (an
 * authorization the shopper just made), so the bundled Stripe gateway does
 * not implement this contract: a check-then-cancel there could release an
 * authorization instead of applying the stranded-payment policy.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.4.0
 */
interface CancelsPaymentSessions
{
    /**
     * Cancels the unconfirmed session `$reference` at the provider.
     *
     * Implementations MUST be idempotent: a session the provider reports as
     * already cancelled or expired (or that does not exist) is a successful
     * no-op. They MUST throw, and never cancel or refund anything, when the
     * session is confirmed (authorized, captured, or still processing), and
     * they MUST throw when the provider doesn't confirm the cancellation.
     * Returning normally means "this session can no longer take a payment".
     *
     * @since 1.4.0
     *
     * @param  string  $reference  {@see \ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession::$reference}.
     *
     * @throws Throwable When the session is confirmed, the provider refuses, or the outcome is unknown.
     *
     * @return void
     */
    public function cancelPaymentSession( string $reference ): void;
}
