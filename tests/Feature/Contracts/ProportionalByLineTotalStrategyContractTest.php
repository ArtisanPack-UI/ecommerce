<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\FulfillmentAllocationStrategy;
use ArtisanPackUI\Ecommerce\Fulfillment\ProportionalByLineTotalStrategy;
use ArtisanPackUI\Ecommerce\Testing\Contracts\FulfillmentAllocationStrategyContractTest;

/**
 * Verifies the reference {@see ProportionalByLineTotalStrategy} satisfies
 * the shared {@see FulfillmentAllocationStrategyContractTest} suite.
 *
 * @since 1.0.0
 */
final class ProportionalByLineTotalStrategyContractTest extends FulfillmentAllocationStrategyContractTest
{
    /**
     * @since 1.0.0
     *
     * @return FulfillmentAllocationStrategy
     */
    protected function strategy(): FulfillmentAllocationStrategy
    {
        return new ProportionalByLineTotalStrategy();
    }
}
