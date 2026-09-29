<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PromotionAction;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionActionContractTest;

/**
 * Verifies the reference {@see FixedOffCartAction} satisfies the shared
 * {@see PromotionActionContractTest} suite.
 *
 * @since 1.0.0
 */
final class FixedOffCartActionContractTest extends PromotionActionContractTest
{
    protected function action(): PromotionAction
    {
        return $this->app->make( PromotionActionRegistry::class )->get( 'fixed-off-cart' );
    }

    protected function applicableCase(): array
    {
        return [ $this->makePersistedCart( [ [ 'unit' => 1_000 ], [ 'unit' => 500 ] ] ), [ 'amount' => 700 ] ];
    }
}
