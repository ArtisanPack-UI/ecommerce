<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionConditionContractTest;

/**
 * Verifies the reference {@see CartContainsProductTypeCondition} satisfies
 * the shared {@see PromotionConditionContractTest} suite.
 *
 * @since 1.0.0
 */
final class CartContainsProductTypeConditionContractTest extends PromotionConditionContractTest
{
    protected function condition(): PromotionCondition
    {
        return $this->app->make( PromotionConditionRegistry::class )->get( 'cart-contains-product-type' );
    }

    protected function satisfiedCase(): array
    {
        return [ $this->makePersistedCart( [ [ 'product' => [ 'type' => 'digital' ] ] ] ), [ 'types' => [ 'digital' ], 'match' => 'only' ] ];
    }

    protected function unsatisfiedCase(): array
    {
        return [ $this->makePersistedCart( [ [ 'product' => [ 'type' => 'digital' ] ], [ 'product' => [ 'type' => 'simple' ] ] ] ), [ 'types' => [ 'digital' ], 'match' => 'only' ] ];
    }
}
