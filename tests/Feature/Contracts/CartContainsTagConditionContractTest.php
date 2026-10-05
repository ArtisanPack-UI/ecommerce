<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionConditionContractTest;

/**
 * Verifies the `cart-contains-tag` condition satisfies the shared
 * {@see PromotionConditionContractTest} suite.
 *
 * @since 1.0.0
 */
final class CartContainsTagConditionContractTest extends PromotionConditionContractTest
{
    protected function condition(): PromotionCondition
    {
        return $this->app->make( PromotionConditionRegistry::class )->get( 'cart-contains-tag' );
    }

    protected function satisfiedCase(): array
    {
        $sale  = ProductTag::factory()->create();
        $other = ProductTag::factory()->create();
        $mug   = Product::factory()->create();
        $mug->tags()->attach( [ $sale->id, $other->id ] );

        return [ $this->makePersistedCart( [ [ 'product' => $mug ] ] ), [ 'tag_ids' => [ $sale->id, $other->id ], 'match' => 'all' ] ];
    }

    protected function unsatisfiedCase(): array
    {
        $sale  = ProductTag::factory()->create();
        $other = ProductTag::factory()->create();
        $mug   = Product::factory()->create();
        $mug->tags()->attach( $sale->id );

        return [ $this->makePersistedCart( [ [ 'product' => $mug ] ] ), [ 'tag_ids' => [ $sale->id, $other->id ], 'match' => 'all' ] ];
    }
}
