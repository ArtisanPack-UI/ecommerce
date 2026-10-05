<?php

/**
 * ClientPaymentConfig.
 *
 * Resolves what a storefront needs to render a gateway's payment step
 * (engine issue #167): the gateway's own
 * {@see RendersClientPayment::clientConfig()}, a `redirect` description
 * when the gateway only gives a redirect URL, or null when it can't be
 * rendered. Either way the result runs through the
 * `ap.ecommerce.payment.clientConfig` filter
 * `(?array $config, PaymentGateway $gateway, Cart $cart, PaymentSession $session)`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Contracts\RendersClientPayment;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ClientPaymentConfig
{
    /**
     * The client config for `$session`, or null when the gateway's step
     * can't be rendered by a storefront.
     *
     * @since 1.0.0
     *
     * @param  PaymentGateway  $gateway  Gateway the session belongs to.
     * @param  Cart            $cart     Cart being paid for.
     * @param  PaymentSession  $session  The session.
     *
     * @return array<string, mixed>|null
     */
    public static function for( PaymentGateway $gateway, Cart $cart, PaymentSession $session ): ?array
    {
        $config = match ( true ) {
            $gateway instanceof RendersClientPayment => $gateway->clientConfig( $cart, $session ),
            null !== $session->redirectUrl           => [
                'driver'          => 'redirect',
                'flow'            => 'redirect',
                'publishable_key' => null,
                'client_secret'   => null,
                'redirect_url'    => $session->redirectUrl,
                'options'         => [],
            ],
            default                                 => null,
        };

        $filtered = applyFilters( 'ap.ecommerce.payment.clientConfig', $config, $gateway, $cart, $session );

        return is_array( $filtered ) ? $filtered + [ 'gateway' => $gateway->key() ] : null;
    }
}
