<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\FraudProvider;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Services\Fraud\StripeRadarFraudProvider;
use ArtisanPackUI\Ecommerce\Testing\Contracts\FraudProviderContractTest;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;

/**
 * Verifies the reference {@see StripeRadarFraudProvider} satisfies the shared
 * {@see FraudProviderContractTest} suite.
 *
 * @since 1.0.0
 */
final class StripeRadarFraudProviderContractTest extends FraudProviderContractTest
{
    protected function provider(): FraudProvider
    {
        // Stub Stripe client: the PaymentIntent id picks the Radar risk level.
        $client = new class {
            public object $paymentIntents;

            public function __construct()
            {
                $this->paymentIntents = new class {
                    public function retrieve( string $id, array $opts = [] ): object
                    {
                        $risk = 'pi_high_risk' === $id ? 'highest' : 'normal';

                        return (object) [
                            'latest_charge' => (object) [
                                'id'      => 'ch_' . $id,
                                'outcome' => (object) [ 'risk_level' => $risk, 'risk_score' => 'highest' === $risk ? 91 : 8 ],
                            ],
                        ];
                    }
                };
            }
        };

        return new StripeRadarFraudProvider( static fn (): object => $client );
    }

    protected function highRiskSession( Cart $cart ): ?PaymentSession
    {
        return $this->paymentSessionFor( $cart, 'pi_high_risk' );
    }
}
