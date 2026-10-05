<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionConditionContractTest;

/**
 * Verifies the reference {@see CartContainsProductCondition} satisfies the shared
 * {@see PromotionConditionContractTest} suite.
 *
 * @since 1.0.0
 */
final class CartContainsProductConditionContractTest extends PromotionConditionContractTest
{
    protected function condition(): PromotionCondition
    {
        return $this->app->make( PromotionConditionRegistry::class )->get( 'cart-contains-product' );
    }

    protected function satisfiedCase(): array
    {
        $product = Product::factory()->create();

        return [ $this->makePersistedCart( [ [ 'product' => $product, 'qty' => 2 ] ] ), [ 'product_ids' => [ $product->id ], 'min_quantity' => 2 ] ];
    }

    protected function unsatisfiedCase(): array
    {
        $product = Product::factory()->create();

        return [ $this->makePersistedCart( [ [ 'product' => $product ] ] ), [ 'product_ids' => [ $product->id ], 'min_quantity' => 2 ] ];
    }
}
