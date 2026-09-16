<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\OrderNumberGenerator;
use ArtisanPackUI\Ecommerce\Services\RandomEightCharGenerator;
use ArtisanPackUI\Ecommerce\Testing\Contracts\OrderNumberGeneratorContractTest;

/**
 * Verifies the reference {@see RandomEightCharGenerator} satisfies the
 * shared {@see OrderNumberGeneratorContractTest} suite.
 *
 * @since 1.0.0
 */
final class RandomEightCharGeneratorContractTest extends OrderNumberGeneratorContractTest
{
    /**
     * @since 1.0.0
     *
     * @return OrderNumberGenerator
     */
    protected function generator(): OrderNumberGenerator
    {
        return new RandomEightCharGenerator();
    }
}
