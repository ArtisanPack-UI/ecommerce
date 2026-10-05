<?php

/**
 * RendersClientPayment contract.
 *
 * Optional companion to {@see PaymentGateway} for gateways whose payment
 * step runs in the shopper's browser. It describes that step in a
 * framework-agnostic way, so the Livewire, React, and Vue storefronts (and
 * every gateway satellite) agree on one shape:
 *
 * ```
 * [
 *     'driver'          => 'stripe-payment-element', // which client component renders it
 *     'flow'            => 'embedded',               // or 'redirect'
 *     'publishable_key' => 'pk_…',                   // optional
 *     'client_secret'   => 'pi_…_secret_…',          // optional
 *     'redirect_url'    => null,                     // for redirect flows
 *     'options'         => [ … ],                    // driver-specific (locale, appearance, …)
 * ]
 * ```
 *
 * Driver names are the storefronts' lookup keys. Known drivers:
 * `stripe-payment-element` (core), `paypal-buttons`, `square-web-payments`,
 * and `redirect` (any gateway that sends the shopper away and back).
 *
 * PCI: the array may only hold values the provider intends to be public
 * (publishable keys, client secrets scoped to one payment). Card data never
 * reaches the server: the client component sends it straight to the
 * provider (parent plan §8.5).
 *
 * Gateways without this contract are rendered as `redirect` when their
 * session has a `redirectUrl`, otherwise they are not renderable. Hosts
 * adjust the result through the `ap.ecommerce.payment.clientConfig` filter
 * (see {@see \ArtisanPackUI\Ecommerce\Support\ClientPaymentConfig}).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface RendersClientPayment
{
    /**
     * Describes the client-side payment step for `$session`.
     *
     * @since 1.0.0
     *
     * @param  Cart            $cart     Cart being paid for.
     * @param  PaymentSession  $session  Session from {@see PaymentGateway::createPaymentSession()}.
     *
     * @return array{driver: string, flow: string, publishable_key?: string|null, client_secret?: string|null, redirect_url?: string|null, options: array<string, mixed>}
     */
    public function clientConfig( Cart $cart, PaymentSession $session ): array;
}
