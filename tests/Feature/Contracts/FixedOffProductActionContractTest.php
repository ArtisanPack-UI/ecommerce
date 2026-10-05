<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionAction;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionActionContractTest;

/**
 * Verifies the `fixed-off-product` action satisfies the shared
 * {@see PromotionActionContractTest} suite.
 *
 * @since 1.0.0
 */
final class FixedOffProductActionContractTest extends PromotionActionContractTest
{
    protected function action(): PromotionAction
    {
        return $this->app->make( PromotionActionRegistry::class )->get( 'fixed-off-product' );
    }

    protected function applicableCase(): array
    {
        $mug = Product::factory()->create();

        return [ $this->makePersistedCart( [ [ 'product' => $mug, 'unit' => 1_000, 'qty' => 2 ], [ 'unit' => 500 ] ] ), [ 'amount' => 300, 'product_ids' => [ $mug->id ] ] ];
    }
}
