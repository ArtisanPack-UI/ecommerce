<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\KanbanAutomationTriggerContractTest;

/**
 * Verifies the built-in `create-shipment` automation trigger satisfies the shared
 * {@see KanbanAutomationTriggerContractTest} suite.
 *
 * @since 1.0.0
 */
final class CreateShipmentTriggerContractTest extends KanbanAutomationTriggerContractTest
{
    protected function trigger(): KanbanAutomationTrigger
    {
        return $this->app->make( KanbanAutomationRegistry::class )->get( 'create-shipment' );
    }

    protected function validConfig(): array
    {
        return [ 'method_key' => 'flat-rate', 'carrier' => 'usps' ];
    }

    protected function invalidConfig(): array
    {
        return [ 'carrier' => 'usps' ];
    }

    protected function assertFired( Order $order, KanbanAutomation $automation ): void
    {
        $shipment = $order->shipments()->first();

        expect( $shipment )->not->toBeNull()
            ->and( $shipment->method_key )->toBe( 'flat-rate' )
            ->and( $shipment->carrier )->toBe( 'usps' );
    }
}
