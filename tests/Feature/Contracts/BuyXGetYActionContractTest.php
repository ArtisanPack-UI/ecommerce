<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionAction;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionActionContractTest;

/**
 * Verifies the reference {@see BuyXGetYAction} satisfies the shared
 * {@see PromotionActionContractTest} suite.
 *
 * @since 1.0.0
 */
final class BuyXGetYActionContractTest extends PromotionActionContractTest
{
    protected function action(): PromotionAction
    {
        return $this->app->make( PromotionActionRegistry::class )->get( 'buy-x-get-y' );
    }

    protected function applicableCase(): array
    {
        $product = Product::factory()->create();

        return [ $this->makePersistedCart( [ [ 'product' => $product, 'unit' => 1_200, 'qty' => 6 ] ] ), [ 'buy_product_ids' => [ $product->id ], 'buy_quantity' => 2, 'get_quantity' => 1 ] ];
    }
}
