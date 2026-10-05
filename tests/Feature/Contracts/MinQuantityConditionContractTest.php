<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionConditionContractTest;

/**
 * Verifies the `min-quantity` condition satisfies the shared
 * {@see PromotionConditionContractTest} suite.
 *
 * @since 1.0.0
 */
final class MinQuantityConditionContractTest extends PromotionConditionContractTest
{
    protected function condition(): PromotionCondition
    {
        return $this->app->make( PromotionConditionRegistry::class )->get( 'min-quantity' );
    }

    protected function satisfiedCase(): array
    {
        return [ $this->makePersistedCart( [ [ 'qty' => 2 ], [ 'qty' => 1 ] ] ), [ 'quantity' => 3 ] ];
    }

    protected function unsatisfiedCase(): array
    {
        return [ $this->makePersistedCart( [ [ 'qty' => 2 ] ] ), [ 'quantity' => 3 ] ];
    }
}
