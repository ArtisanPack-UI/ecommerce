<?php

/**
 * PaymentResult value object.
 *
 * Return type of {@see \ArtisanPackUI\Ecommerce\Contracts\PaymentGateway::capturePayment()}.
 *
 * Carries the outcome of a provider-side capture call: whether it succeeded,
 * the amount that was captured, the provider-side reference id (for later
 * reconciliation), and — on failure — the error code / message plus whether
 * the caller may retry (network blip, provider brownout) or must treat the
 * failure as terminal (declined card, insufficient funds).
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
final class PaymentResult
{
    /**
     * @since 1.0.0
     *
     * @param  bool         $success            Whether the gateway accepted and captured the payment.
     * @param  Money        $amount             Amount that was captured (on success) or attempted (on failure).
     * @param  string|null  $gatewayReference   Provider-side identifier (Stripe charge id, PayPal capture id, etc.).
     * @param  string|null  $errorCode          Machine-readable code when `$success` is false.
     * @param  string|null  $errorMessage       Human-readable failure reason when `$success` is false.
     * @param  bool         $retryable          Whether the caller may retry the capture. Meaningful only when `$success` is false.
     */
    public function __construct(
        public readonly bool $success,
        public readonly Money $amount,
        public readonly ?string $gatewayReference = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $retryable = false,
    ) {
    }

    /**
     * Convenience constructor for a successful capture.
     *
     * @since 1.0.0
     *
     * @param  Money        $amount             The amount that was captured.
     * @param  string|null  $gatewayReference   Provider-side identifier.
     *
     * @return self
     */
    public static function success( Money $amount, ?string $gatewayReference = null ): self
    {
        return new self( true, $amount, $gatewayReference );
    }

    /**
     * Convenience constructor for a terminal (do-not-retry) capture failure.
     *
     * A terminal failure is the caller's signal that another attempt will not
     * change the outcome — declined card, insufficient funds, provider account
     * disabled. Callers should surface the error to the customer and let them
     * choose another payment method rather than retry the same one.
     *
     * @since 1.0.0
     *
     * @param  Money        $amount         Amount that was attempted.
     * @param  string       $errorCode      Machine-readable code.
     * @param  string|null  $errorMessage   Human-readable message.
     *
     * @return self
     */
    public static function terminalFailure( Money $amount, string $errorCode, ?string $errorMessage = null ): self
    {
        return new self( false, $amount, null, $errorCode, $errorMessage, false );
    }

    /**
     * Convenience constructor for a retryable capture failure.
     *
     * A retryable failure is a transient error (rate limit, network blip,
     * provider brownout) where the caller may safely retry the capture,
     * ideally with backoff.
     *
     * @since 1.0.0
     *
     * @param  Money        $amount         Amount that was attempted.
     * @param  string       $errorCode      Machine-readable code.
     * @param  string|null  $errorMessage   Human-readable message.
     *
     * @return self
     */
    public static function retryableFailure( Money $amount, string $errorCode, ?string $errorMessage = null ): self
    {
        return new self( false, $amount, null, $errorCode, $errorMessage, true );
    }
}
