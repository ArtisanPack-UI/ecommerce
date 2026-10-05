<?php

/**
 * StripeRadarFraudProvider.
 *
 * Reference {@see \ArtisanPackUI\Ecommerce\Contracts\FraudProvider}
 * implementation backed by Stripe Radar. Reads the risk level Stripe
 * attached to the latest charge on the authorized PaymentIntent
 * (`charge.outcome.risk_level`) and maps it onto a {@see FraudDecision}
 * verdict.
 *
 * Radar risk levels (per Stripe docs) map as follows:
 *
 * - `normal`, `not_assessed`, `unknown`  → approve
 * - `elevated`                           → challenge (step-up, e.g. 3DS)
 * - `highest`                            → block
 *
 * Sessions that were not created through the Stripe gateway are approved
 * with a `not_applicable` reason — Radar has nothing to say about a
 * PayPal or Braintree authorization.
 *
 * Radar scores a charge, and a PaymentIntent only has one once the shopper
 * has confirmed it. A session with no charge yet is therefore challenged
 * (`no_charge`), not approved: checkout finalizes the confirmed session,
 * which does have one. When Stripe can't be reached the result follows
 * `artisanpack.ecommerce.fraud.fail_open`: approve with a `provider_error`
 * reason when true, otherwise challenge, which holds the payment for review.
 *
 * Engine plan §6.1 + §8.4.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services\Fraud;

use ArtisanPackUI\Ecommerce\Contracts\FraudProvider;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeGateway;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\FraudDecision;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class StripeRadarFraudProvider implements FraudProvider
{
    /**
     * @since 1.0.0
     */
    public const KEY = 'stripe-radar';

    /**
     * Radar risk levels that map to a {@see FraudDecision::block()} verdict.
     *
     * @since 1.0.0
     */
    private const RISK_LEVEL_BLOCK = 'highest';

    /**
     * Radar risk levels that map to a {@see FraudDecision::challenge()} verdict.
     *
     * @since 1.0.0
     */
    private const RISK_LEVEL_CHALLENGE = 'elevated';

    /**
     * Client resolver — invoked lazily on each assessment. Container
     * wiring passes a closure over {@see StripeClientFactory::make()};
     * tests can inject a fake without extending the factory (whose
     * `make()` return type is bound to the real SDK class).
     *
     * @since 1.0.0
     *
     * @var Closure(): object
     */
    private readonly Closure $clientResolver;

    /**
     * @since 1.0.0
     *
     * @param  Closure  $clientResolver  Closure returning any object exposing `->paymentIntents->retrieve()`.
     */
    public function __construct( Closure $clientResolver )
    {
        $this->clientResolver = $clientResolver;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Stripe Radar' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart            $cart
     * @param  Address         $shipping
     * @param  PaymentSession  $session
     *
     * @return FraudDecision
     */
    public function assess( Cart $cart, Address $shipping, PaymentSession $session ): FraudDecision
    {
        if ( StripeGateway::KEY !== $session->gatewayKey ) {
            return FraudDecision::approve( 0, [ 'not_applicable' ] );
        }

        try {
            $client = ( $this->clientResolver )();
            $intent = $client->paymentIntents->retrieve(
                $session->reference,
                [ 'expand' => [ 'latest_charge' ] ],
            );
        } catch ( Throwable $e ) {
            $failOpen = (bool) config( 'artisanpack.ecommerce.fraud.fail_open', false );

            Log::channel( 'ecommerce' )->warning(
                $failOpen
                    ? 'Stripe Radar assessment failed; approving (fraud.fail_open) with provider_error reason.'
                    : 'Stripe Radar assessment failed; holding the payment for review.',
                [
                    'session_reference' => $session->reference,
                    'exception'         => $e::class,
                    'message'           => $e->getMessage(),
                ],
            );

            return $failOpen
                ? FraudDecision::approve( 0, [ 'provider_error' ] )
                : FraudDecision::challenge( 0, [ 'provider_error' ] );
        }

        $charge = $intent->latest_charge ?? null;

        // Nothing to score until the shopper confirms the payment.
        if ( ! is_object( $charge ) ) {
            return FraudDecision::challenge( 0, [ 'no_charge' ], $session->reference );
        }

        $outcome   = $charge->outcome ?? null;
        $riskLevel = is_object( $outcome ) ? (string) ( $outcome->risk_level ?? '' ) : '';
        // Clamp to [0, 100] — FraudDecision throws on out-of-range values and
        // Stripe is not contractually pinned to that range for `risk_score`.
        $riskScore = is_object( $outcome ) && isset( $outcome->risk_score )
            ? max( 0, min( 100, (int) $outcome->risk_score ) )
            : 0;
        $chargeId  = isset( $charge->id ) ? (string) $charge->id : $session->reference;
        $reasons   = [ 'stripe_radar', 'risk_level:' . ( '' === $riskLevel ? 'unknown' : $riskLevel ) ];

        if ( self::RISK_LEVEL_BLOCK === $riskLevel ) {
            return FraudDecision::block( $riskScore, $reasons, $chargeId );
        }

        if ( self::RISK_LEVEL_CHALLENGE === $riskLevel ) {
            return FraudDecision::challenge( $riskScore, $reasons, $chargeId );
        }

        return FraudDecision::approve( $riskScore, $reasons, $chargeId );
    }
}
