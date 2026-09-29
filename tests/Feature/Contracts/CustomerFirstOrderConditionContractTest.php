<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionConditionContractTest;

/**
 * Verifies the reference {@see CustomerFirstOrderCondition} satisfies the shared
 * {@see PromotionConditionContractTest} suite.
 *
 * @since 1.0.0
 */
final class CustomerFirstOrderConditionContractTest extends PromotionConditionContractTest
{
    protected function condition(): PromotionCondition
    {
        return $this->app->make( PromotionConditionRegistry::class )->get( 'customer-first-order' );
    }

    protected function satisfiedCase(): array
    {
        $customer = Customer::factory()->create();

        return [ $this->makePersistedCart( [ [] ], 'USD', [ 'customer_id' => $customer->id ] ), [] ];
    }

    protected function unsatisfiedCase(): array
    {
        $customer = Customer::factory()->create();
        Order::factory()->forCustomer( $customer )->create();

        return [ $this->makePersistedCart( [ [] ], 'USD', [ 'customer_id' => $customer->id ] ), [] ];
    }
}
