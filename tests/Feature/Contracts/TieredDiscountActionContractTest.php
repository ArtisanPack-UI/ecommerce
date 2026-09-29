<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionAction;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionActionContractTest;

/**
 * Verifies the reference {@see TieredDiscountAction} satisfies the shared
 * {@see PromotionActionContractTest} suite.
 *
 * @since 1.0.0
 */
final class TieredDiscountActionContractTest extends PromotionActionContractTest
{
    protected function action(): PromotionAction
    {
        return $this->app->make( PromotionActionRegistry::class )->get( 'tiered-discount' );
    }

    protected function applicableCase(): array
    {
        return [ $this->makePersistedCart( [ [ 'unit' => 12_000 ] ] ), [ 'tiers' => [ [ 'min_subtotal' => 5_000, 'percent' => 5 ], [ 'min_subtotal' => 10_000, 'amount' => 2_000 ] ] ] ];
    }
}
