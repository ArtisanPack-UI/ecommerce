<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\KanbanAutomationTriggerContractTest;

/**
 * Verifies the built-in `update-order-field` automation trigger satisfies the shared
 * {@see KanbanAutomationTriggerContractTest} suite.
 *
 * @since 1.0.0
 */
final class UpdateOrderFieldTriggerContractTest extends KanbanAutomationTriggerContractTest
{
    protected function trigger(): KanbanAutomationTrigger
    {
        return $this->app->make( KanbanAutomationRegistry::class )->get( 'update-order-field' );
    }

    protected function validConfig(): array
    {
        return [ 'field' => 'meta.kanban.stage', 'value' => 'printing' ];
    }

    protected function invalidConfig(): array
    {
        return [ 'field' => 'total_amount', 'value' => 1 ];
    }

    protected function assertFired( Order $order, KanbanAutomation $automation ): void
    {
        expect( $order->fresh()->meta['kanban']['stage'] ?? null )->toBe( 'printing' );
    }
}
