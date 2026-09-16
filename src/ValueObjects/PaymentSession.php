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
     * @since 1.0.0
     *
     * @param  string                $gatewayKey        Machine-readable key of the gateway that produced this session.
     * @param  string                $reference         Provider-side identifier (PaymentIntent id, PayPal Order id, etc.).
     * @param  Money                 $amount            Amount the session was created for, in the cart's payment currency.
     * @param  string|null           $clientSecret      Provider-side client secret (Stripe) — never surface to logs.
     * @param  string|null           $redirectUrl       Provider-hosted redirect URL when the flow requires one (PayPal approval, 3DS, etc.).
     * @param  array<string, mixed>  $metadata          Free-form provider metadata forwarded to the storefront (client id, publishable key hints, etc.).
     */
    public function __construct(
        public readonly string $gatewayKey,
        public readonly string $reference,
        public readonly Money $amount,
        public readonly ?string $clientSecret = null,
        public readonly ?string $redirectUrl = null,
        public readonly array $metadata = [],
    ) {
    }
}
