<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionAction;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionActionContractTest;

/**
 * Verifies the reference {@see PercentOffProductAction} satisfies the shared
 * {@see PromotionActionContractTest} suite.
 *
 * @since 1.0.0
 */
final class PercentOffProductActionContractTest extends PromotionActionContractTest
{
    protected function action(): PromotionAction
    {
        return $this->app->make( PromotionActionRegistry::class )->get( 'percent-off-product' );
    }

    protected function applicableCase(): array
    {
        $product = Product::factory()->create();

        return [ $this->makePersistedCart( [ [ 'product' => $product, 'unit' => 2_000 ], [ 'unit' => 900 ] ] ), [ 'percent' => 40, 'product_ids' => [ $product->id ] ] ];
    }
}
