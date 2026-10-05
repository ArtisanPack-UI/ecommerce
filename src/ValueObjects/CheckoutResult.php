<?php

/**
 * CheckoutResult.
 *
 * The outcome of {@see \ArtisanPackUI\Ecommerce\Services\CheckoutService::finalize()}:
 * the order and how its payment went. `$payment` is null for an order that
 * needed no payment (a zero total).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ValueObjects;

use ArtisanPackUI\Ecommerce\Models\Order;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class CheckoutResult
{
    /**
     * @since 1.0.0
     *
     * @param  Order                     $order    The order.
     * @param  PaymentFinalization|null  $payment  The payment outcome (null when no payment was needed).
     */
    public function __construct(
        public readonly Order $order,
        public readonly ?PaymentFinalization $payment = null,
    ) {
    }

    /**
     * `captured`, `challenged`, `blocked`, or `failed` (see
     * {@see PaymentFinalization}); `captured` for an order that needed no
     * payment.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function status(): string
    {
        return $this->payment?->status ?? PaymentFinalization::STATUS_CAPTURED;
    }

    /**
     * Whether the order is paid (or needed no payment).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isComplete(): bool
    {
        return PaymentFinalization::STATUS_CAPTURED === $this->status();
    }

    /**
     * The step-up token the shopper must complete (3DS, a redirect) before
     * finalizing again, if any.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function stepUpToken(): ?string
    {
        return $this->payment?->stepUpToken;
    }
}
