<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionConditionContractTest;

/**
 * Verifies the `customer-lifetime-value-over` condition satisfies the shared
 * {@see PromotionConditionContractTest} suite.
 *
 * @since 1.0.0
 */
final class CustomerLifetimeValueOverConditionContractTest extends PromotionConditionContractTest
{
    protected function condition(): PromotionCondition
    {
        return $this->app->make( PromotionConditionRegistry::class )->get( 'customer-lifetime-value-over' );
    }

    protected function satisfiedCase(): array
    {
        $customer = Customer::factory()->create();
        $customer->forceFill( [ 'total_spent_amount' => 50_001, 'total_spent_currency' => 'USD' ] )->save();

        return [ $this->makePersistedCart( [ [] ], 'USD', [ 'customer_id' => $customer->id ] ), [ 'amount' => 50_000 ] ];
    }

    protected function unsatisfiedCase(): array
    {
        $customer = Customer::factory()->create();
        $customer->forceFill( [ 'total_spent_amount' => 50_000, 'total_spent_currency' => 'USD' ] )->save();

        return [ $this->makePersistedCart( [ [] ], 'USD', [ 'customer_id' => $customer->id ] ), [ 'amount' => 50_000 ] ];
    }
}
