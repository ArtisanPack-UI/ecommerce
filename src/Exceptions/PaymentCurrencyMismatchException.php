<?php

/**
 * PaymentCurrencyMismatchException.
 *
 * Thrown from {@see \ArtisanPackUI\Ecommerce\Contracts\PaymentGateway::capturePayment()}
 * and {@see \ArtisanPackUI\Ecommerce\Contracts\PaymentGateway::refund()} when
 * the value being moved does not match the order's payment currency. The
 * engine never silently converts on FX drift — a currency mismatch is a
 * programmer error at the call site, so implementations MUST throw rather
 * than return a failure result the caller might treat as retryable.
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
class PaymentCurrencyMismatchException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  string  $expected  ISO 4217 currency code the payment was expected in.
     * @param  string  $actual    ISO 4217 currency code the caller passed.
     */
    public function __construct(
        public readonly string $expected,
        public readonly string $actual,
    ) {
        parent::__construct(
            sprintf(
                'Payment currency mismatch: expected %s, got %s.',
                $expected,
                $actual,
            ),
        );
    }
}
