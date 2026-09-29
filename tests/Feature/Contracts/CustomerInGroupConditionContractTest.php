<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionConditionContractTest;

/**
 * Verifies the reference {@see CustomerInGroupCondition} satisfies the shared
 * {@see PromotionConditionContractTest} suite.
 *
 * @since 1.0.0
 */
final class CustomerInGroupConditionContractTest extends PromotionConditionContractTest
{
    protected function condition(): PromotionCondition
    {
        return $this->app->make( PromotionConditionRegistry::class )->get( 'customer-in-group' );
    }

    protected function satisfiedCase(): array
    {
        $customer = Customer::factory()->create( [ 'meta' => [ 'groups' => [ 'wholesale' ] ] ] );

        return [ $this->makePersistedCart( [ [] ], 'USD', [ 'customer_id' => $customer->id ] ), [ 'groups' => [ 'wholesale' ] ] ];
    }

    protected function unsatisfiedCase(): array
    {
        $customer = Customer::factory()->create( [ 'meta' => [ 'groups' => [ 'retail' ] ] ] );

        return [ $this->makePersistedCart( [ [] ], 'USD', [ 'customer_id' => $customer->id ] ), [ 'groups' => [ 'wholesale' ] ] ];
    }
}
