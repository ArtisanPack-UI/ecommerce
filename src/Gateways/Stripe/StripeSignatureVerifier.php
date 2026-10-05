<?php

/**
 * StripeSignatureVerifier.
 *
 * Thin wrapper around {@see \Stripe\Webhook::constructEvent()} so
 * {@see StripeGateway} can verify inbound webhooks without a hard
 * coupling to the SDK's static call surface. Tests substitute a fake
 * verifier via container binding to exercise both accept + reject paths
 * without regenerating real Stripe signatures.
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

use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class StripeSignatureVerifier
{
    /**
     * Tolerance, in seconds, allowed between the timestamp encoded in the
     * signature header and the server clock. Matches Stripe's default.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verifies a Stripe webhook signature against the shared secret.
     *
     * Delegates to {@see Webhook::constructEvent()} which performs the
     * timing-safe HMAC-SHA256 comparison and tolerance check.
     *
     * @since 1.0.0
     *
     * @param  string  $payload    Raw request body, exactly as received.
     * @param  string  $signature  Value of the `Stripe-Signature` header.
     * @param  string  $secret     Endpoint secret (`whsec_…`).
     * @param  int     $tolerance  Allowed timestamp drift in seconds.
     *
     * @throws SignatureVerificationException When the signature does not verify.
     *
     * @return Event
     */
    public function verify( string $payload, string $signature, string $secret, int $tolerance = self::DEFAULT_TOLERANCE ): Event
    {
        return Webhook::constructEvent( $payload, $signature, $secret, $tolerance );
    }
}
