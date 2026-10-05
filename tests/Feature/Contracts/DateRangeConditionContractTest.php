<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionConditionContractTest;

/**
 * Verifies the `date-range` condition satisfies the shared
 * {@see PromotionConditionContractTest} suite.
 *
 * @since 1.0.0
 */
final class DateRangeConditionContractTest extends PromotionConditionContractTest
{
    protected function condition(): PromotionCondition
    {
        return $this->app->make( PromotionConditionRegistry::class )->get( 'date-range' );
    }

    protected function satisfiedCase(): array
    {
        return [ $this->makePersistedCart( [ [] ] ), [ 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString() ] ];
    }

    protected function unsatisfiedCase(): array
    {
        return [ $this->makePersistedCart( [ [] ] ), [ 'ends_on' => now()->subDays( 2 )->toDateString() ] ];
    }
}
