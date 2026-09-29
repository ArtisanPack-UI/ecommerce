<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionConditionContractTest;

/**
 * Verifies the reference {@see MinSubtotalCondition} satisfies the shared
 * {@see PromotionConditionContractTest} suite.
 *
 * @since 1.0.0
 */
final class MinSubtotalConditionContractTest extends PromotionConditionContractTest
{
    protected function condition(): PromotionCondition
    {
        return $this->app->make( PromotionConditionRegistry::class )->get( 'min-subtotal' );
    }

    protected function satisfiedCase(): array
    {
        return [ $this->makePersistedCart( [ [ 'unit' => 8_000 ] ] ), [ 'amount' => 7_500 ] ];
    }

    protected function unsatisfiedCase(): array
    {
        return [ $this->makePersistedCart( [ [ 'unit' => 7_499 ] ] ), [ 'amount' => 7_500 ] ];
    }
}
