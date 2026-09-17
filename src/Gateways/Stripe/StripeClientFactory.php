<?php

/**
 * StripeClientFactory.
 *
 * Builds a configured {@see StripeClient} from the package config. Bound as
 * a singleton in {@see \ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider}
 * so tests can rebind it to inject a mock HTTP client without touching the
 * gateway.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Gateways\Stripe;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;
use Stripe\StripeClient;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class StripeClientFactory
{
    /**
     * @since 1.0.0
     *
     * @param  Repository  $config  Laravel config repository.
     */
    public function __construct( private readonly Repository $config )
    {
    }

    /**
     * Builds a {@see StripeClient} using the configured secret key.
     *
     * @since 1.0.0
     *
     * @throws RuntimeException When the secret key is not configured.
     *
     * @return StripeClient
     */
    public function make(): StripeClient
    {
        $secret = (string) $this->config->get( 'artisanpack.ecommerce.gateways.stripe.secret_key', '' );

        if ( '' === $secret ) {
            throw new RuntimeException(
                'Stripe secret key is not configured (artisanpack.ecommerce.gateways.stripe.secret_key).',
            );
        }

        $options = [ 'api_key' => $secret ];

        $version = (string) $this->config->get( 'artisanpack.ecommerce.gateways.stripe.api_version', '' );

        if ( '' !== $version ) {
            $options[ 'stripe_version' ] = $version;
        }

        return new StripeClient( $options );
    }
}
