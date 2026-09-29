<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionAction;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionActionContractTest;

/**
 * Verifies the reference {@see PercentOffCartAction} satisfies the shared
 * {@see PromotionActionContractTest} suite.
 *
 * @since 1.0.0
 */
final class PercentOffCartActionContractTest extends PromotionActionContractTest
{
    protected function action(): PromotionAction
    {
        return $this->app->make( PromotionActionRegistry::class )->get( 'percent-off-cart' );
    }

    protected function applicableCase(): array
    {
        return [ $this->makePersistedCart( [ [ 'unit' => 3_333 ], [ 'unit' => 1_111, 'qty' => 3 ] ] ), [ 'percent' => 35 ] ];
    }
}
