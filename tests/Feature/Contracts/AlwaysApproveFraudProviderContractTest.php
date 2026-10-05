<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\FraudProvider;
use ArtisanPackUI\Ecommerce\Services\Fraud\AlwaysApproveFraudProvider;
use ArtisanPackUI\Ecommerce\Testing\Contracts\FraudProviderContractTest;

/**
 * Verifies the reference {@see AlwaysApproveFraudProvider} satisfies the shared
 * {@see FraudProviderContractTest} suite.
 *
 * @since 1.0.0
 */
final class AlwaysApproveFraudProviderContractTest extends FraudProviderContractTest
{
    protected function provider(): FraudProvider
    {
        return new AlwaysApproveFraudProvider();
    }
}
