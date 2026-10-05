<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionConditionContractTest;

/**
 * Verifies the `cart-contains-category` condition satisfies the shared
 * {@see PromotionConditionContractTest} suite.
 *
 * @since 1.0.0
 */
final class CartContainsCategoryConditionContractTest extends PromotionConditionContractTest
{
    protected function condition(): PromotionCondition
    {
        return $this->app->make( PromotionConditionRegistry::class )->get( 'cart-contains-category' );
    }

    protected function satisfiedCase(): array
    {
        $parent = ProductCategory::factory()->create();
        $child  = ProductCategory::factory()->create( [ 'parent_id' => $parent->id ] );
        $mug    = Product::factory()->create();
        $mug->categories()->attach( $child->id );

        return [ $this->makePersistedCart( [ [ 'product' => $mug ] ] ), [ 'category_ids' => [ $parent->id ] ] ];
    }

    protected function unsatisfiedCase(): array
    {
        $parent = ProductCategory::factory()->create();
        $child  = ProductCategory::factory()->create( [ 'parent_id' => $parent->id ] );
        $mug    = Product::factory()->create();
        $mug->categories()->attach( $child->id );

        return [ $this->makePersistedCart( [ [ 'product' => $mug ] ] ), [ 'category_ids' => [ $parent->id ], 'include_descendants' => false ] ];
    }
}
