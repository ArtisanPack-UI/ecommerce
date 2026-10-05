<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionAction;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionActionContractTest;

/**
 * Verifies the reference {@see AddFreeItemAction} satisfies the shared
 * {@see PromotionActionContractTest} suite.
 *
 * @since 1.0.0
 */
final class AddFreeItemActionContractTest extends PromotionActionContractTest
{
    protected function action(): PromotionAction
    {
        return $this->app->make( PromotionActionRegistry::class )->get( 'add-free-item' );
    }

    protected function applicableCase(): array
    {
        $product = Product::factory()->create();

        return [ $this->makePersistedCart( [ [ 'product' => $product, 'unit' => 800 ] ] ), [ 'product_id' => $product->id, 'quantity' => 1 ] ];
    }
}
