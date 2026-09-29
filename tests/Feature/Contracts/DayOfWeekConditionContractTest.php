<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionConditionContractTest;
use Illuminate\Support\Carbon;

/**
 * Verifies the reference {@see DayOfWeekCondition} satisfies the shared
 * {@see PromotionConditionContractTest} suite.
 *
 * @since 1.0.0
 */
final class DayOfWeekConditionContractTest extends PromotionConditionContractTest
{
    protected function condition(): PromotionCondition
    {
        return $this->app->make( PromotionConditionRegistry::class )->get( 'day-of-week' );
    }

    protected function satisfiedCase(): array
    {
        return [ $this->makePersistedCart( [ [] ] ), [ 'days' => [ Carbon::now( 'UTC' )->dayOfWeekIso ], 'timezone' => 'UTC' ] ];
    }

    protected function unsatisfiedCase(): array
    {
        return [ $this->makePersistedCart( [ [] ] ), [ 'days' => [ Carbon::now( 'UTC' )->addDay()->dayOfWeekIso ], 'timezone' => 'UTC' ] ];
    }
}
