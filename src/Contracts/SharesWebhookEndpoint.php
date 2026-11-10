<?php

/**
 * SharesWebhookEndpoint contract.
 *
 * Optional companion to {@see PaymentGateway} for a provider that offers
 * several payment methods through one account but can only post webhooks to
 * one URL (#207). `ecommerce-paypal`, for example, registers `paypal`,
 * `paypal-card`, `paypal-apple-pay`, and `paypal-google-pay` so checkout can
 * list them as separate choices, while PayPal posts every event to
 * `ecommerce/webhooks/paypal`.
 *
 * When the gateway resolved for the webhook's `{provider}` implements this
 * contract, {@see \ArtisanPackUI\Ecommerce\Services\PaymentReconciler}
 * matches carts and pending orders whose `payment_gateway_key` is the
 * gateway's own key or one of the keys it declares here. Declared keys that
 * aren't registered in
 * {@see \ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry} are
 * dropped, so a gateway can never claim sessions under a key nothing has
 * registered. Gateways without the contract only reconcile their own key.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.3.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.3.0
 */
interface SharesWebhookEndpoint
{
    /**
     * The gateway keys whose payment sessions this gateway's webhooks report
     * on, its own key included.
     *
     * @since 1.3.0
     *
     * @return array<int, string>
     */
    public function webhookGatewayKeys(): array;
}
