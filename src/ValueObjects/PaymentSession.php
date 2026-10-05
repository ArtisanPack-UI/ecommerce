<?php

/**
 * PaymentSession value object.
 *
 * Return type of {@see \ArtisanPackUI\Ecommerce\Contracts\PaymentGateway::createPaymentSession()}
 * and input to {@see \ArtisanPackUI\Ecommerce\Contracts\PaymentGateway::capturePayment()}.
 *
 * Carries the provider-side identity and next-action hints for a payment
 * that has been initiated but not yet captured — Stripe PaymentIntent id
 * plus its client secret, PayPal Order id plus the approval URL, etc.
 *
 * `$status` is the provider state normalized to one of the `STATUS_*`
 * constants (null when the gateway doesn't report one). Checkout reads it
 * to tell a payment the shopper confirmed (`authorized`, `succeeded`,
 * `processing`) from one that still needs them (`requires_action`,
 * `requires_payment_method`) or is dead (`canceled`).
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
final class PaymentSession
{
    /**
     * The shopper hasn't supplied (or a previous attempt rejected) a payment method.
     *
     * @since 1.0.0
     */
    public const STATUS_REQUIRES_PAYMENT_METHOD = 'requires_payment_method';

    /**
     * The shopper must complete a step-up (3DS, a redirect) first.
     *
     * @since 1.0.0
     */
    public const STATUS_REQUIRES_ACTION = 'requires_action';

    /**
     * Confirmed, and the provider is still settling it (bank transfers, some wallets).
     *
     * @since 1.0.0
     */
    public const STATUS_PROCESSING = 'processing';

    /**
     * Confirmed and authorized; waiting for capture.
     *
     * @since 1.0.0
     */
    public const STATUS_AUTHORIZED = 'authorized';

    /**
     * Captured: the money has moved.
     *
     * @since 1.0.0
     */
    public const STATUS_SUCCEEDED = 'succeeded';

    /**
     * Cancelled or expired at the provider; it can't be paid any more.
     *
     * @since 1.0.0
     */
    public const STATUS_CANCELED = 'canceled';

    /**
     * Every normalized status.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_REQUIRES_PAYMENT_METHOD,
        self::STATUS_REQUIRES_ACTION,
        self::STATUS_PROCESSING,
        self::STATUS_AUTHORIZED,
        self::STATUS_SUCCEEDED,
        self::STATUS_CANCELED,
    ];

    /**
     * @since 1.0.0
     *
     * @param  string                $gatewayKey        Machine-readable key of the gateway that produced this session.
     * @param  string                $reference         Provider-side identifier (PaymentIntent id, PayPal Order id, etc.).
     * @param  Money                 $amount            Amount the session was created for, in the cart's payment currency.
     * @param  string|null           $clientSecret      Provider-side client secret (Stripe) — never surface to logs.
     * @param  string|null           $redirectUrl       Provider-hosted redirect URL when the flow requires one (PayPal approval, 3DS, etc.).
     * @param  array<string, mixed>  $metadata          Free-form provider metadata forwarded to the storefront (client id, publishable key hints, etc.).
     * @param  string|null           $status            Normalized provider status (`STATUS_*`), or null when unknown.
     */
    public function __construct(
        public readonly string $gatewayKey,
        public readonly string $reference,
        public readonly Money $amount,
        public readonly ?string $clientSecret = null,
        public readonly ?string $redirectUrl = null,
        public readonly array $metadata = [],
        public readonly ?string $status = null,
    ) {
    }

    /**
     * Whether the shopper has confirmed the payment (authorized, captured,
     * or settling).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isConfirmed(): bool
    {
        return in_array( $this->status, [ self::STATUS_AUTHORIZED, self::STATUS_SUCCEEDED, self::STATUS_PROCESSING ], true );
    }

    /**
     * Whether the money has already been captured.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isCaptured(): bool
    {
        return self::STATUS_SUCCEEDED === $this->status;
    }

    /**
     * Whether the shopper must complete a step-up before it can be captured.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function requiresAction(): bool
    {
        return self::STATUS_REQUIRES_ACTION === $this->status;
    }

    /**
     * Whether the session can no longer be paid (cancelled or expired).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isCanceled(): bool
    {
        return self::STATUS_CANCELED === $this->status;
    }

    /**
     * The step-up token a client needs: the client secret, else the
     * redirect URL.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function stepUpToken(): ?string
    {
        return $this->clientSecret ?? $this->redirectUrl;
    }

    /**
     * Storable identity of the session (no client secret), as kept on a
     * cart or order.
     *
     * @since 1.0.0
     *
     * @return array{gateway: string, reference: string, amount: int, currency: string, status: string|null}
     */
    public function toArray(): array
    {
        return [
            'gateway'   => $this->gatewayKey,
            'reference' => $this->reference,
            'amount'    => (int) $this->amount->getAmount(),
            'currency'  => $this->amount->getCurrency()->getCode(),
            'status'    => $this->status,
        ];
    }
}
