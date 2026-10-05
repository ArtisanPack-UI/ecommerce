<?php

/**
 * RefundResult value object.
 *
 * Return type of {@see \ArtisanPackUI\Ecommerce\Contracts\PaymentGateway::refund()}.
 *
 * Carries the outcome of a provider-side refund call: whether it succeeded,
 * the amount that was actually moved, the provider-side reference id (for
 * later reconciliation), and — on failure — a human-readable error message
 * plus a machine-readable error code.
 *
 * Engine spec §4.2.
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

use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class RefundResult
{
    /**
     * @since 1.0.0
     *
     * @param  bool         $success            Whether the gateway accepted and processed the refund.
     * @param  Money        $amount             Amount that was actually refunded.
     * @param  string|null  $gatewayReference   Provider-side identifier (Stripe refund id, PayPal capture id, etc.).
     * @param  string|null  $errorCode          Machine-readable code when `$success` is false.
     * @param  string|null  $errorMessage       Human-readable failure reason when `$success` is false.
     */
    public function __construct(
        public readonly bool $success,
        public readonly Money $amount,
        public readonly ?string $gatewayReference = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    /**
     * Convenience constructor for a successful refund.
     *
     * @since 1.0.0
     *
     * @param  Money        $amount             The amount that was refunded.
     * @param  string|null  $gatewayReference   Provider-side identifier.
     *
     * @return self
     */
    public static function success( Money $amount, ?string $gatewayReference = null ): self
    {
        return new self( true, $amount, $gatewayReference );
    }

    /**
     * Convenience constructor for a refund the gateway declined.
     *
     * @since 1.0.0
     *
     * @param  Money        $amount         The amount that was attempted.
     * @param  string       $errorCode      Machine-readable code.
     * @param  string|null  $errorMessage   Human-readable message.
     *
     * @return self
     */
    public static function failure( Money $amount, string $errorCode, ?string $errorMessage = null ): self
    {
        return new self( false, $amount, null, $errorCode, $errorMessage );
    }
}
