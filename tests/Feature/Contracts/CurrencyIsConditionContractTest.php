<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionConditionContractTest;

/**
 * Verifies the `currency-is` condition satisfies the shared
 * {@see PromotionConditionContractTest} suite.
 *
 * @since 1.0.0
 */
final class CurrencyIsConditionContractTest extends PromotionConditionContractTest
{
    protected function condition(): PromotionCondition
    {
        return $this->app->make( PromotionConditionRegistry::class )->get( 'currency-is' );
    }

    protected function satisfiedCase(): array
    {
        return [ $this->makePersistedCart( [ [] ], 'USD' ), [ 'currencies' => [ 'USD' ] ] ];
    }

    protected function unsatisfiedCase(): array
    {
        return [ $this->makePersistedCart( [ [] ], 'EUR' ), [ 'currencies' => [ 'USD' ] ] ];
    }
}
