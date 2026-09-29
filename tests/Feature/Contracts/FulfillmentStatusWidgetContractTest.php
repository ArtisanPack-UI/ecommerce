<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\KanbanCardWidget;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\KanbanCardWidgetContractTest;

/**
 * Verifies the built-in `fulfillment-status` card widget satisfies the shared
 * {@see KanbanCardWidgetContractTest} suite.
 *
 * @since 1.0.0
 */
final class FulfillmentStatusWidgetContractTest extends KanbanCardWidgetContractTest
{
    protected function widget(): KanbanCardWidget
    {
        return $this->app->make( KanbanCardWidgetRegistry::class )->get( 'fulfillment-status' );
    }
}
