<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingLabelProviderRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\KanbanAutomationTriggerContractTest;
use Tests\Fixtures\FakeShippingLabelProvider;

/**
 * Verifies the built-in `print-shipping-label` automation trigger satisfies the shared
 * {@see KanbanAutomationTriggerContractTest} suite.
 *
 * @since 1.0.0
 */
final class PrintShippingLabelTriggerContractTest extends KanbanAutomationTriggerContractTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make( ShippingLabelProviderRegistry::class )->register( FakeShippingLabelProvider::KEY, new FakeShippingLabelProvider() );
    }

    protected function trigger(): KanbanAutomationTrigger
    {
        return $this->app->make( KanbanAutomationRegistry::class )->get( 'print-shipping-label' );
    }

    protected function validConfig(): array
    {
        return [ 'provider' => FakeShippingLabelProvider::KEY, 'method_key' => 'flat-rate' ];
    }

    protected function invalidConfig(): array
    {
        return [ 'provider' => 'not-registered' ];
    }

    protected function assertFired( Order $order, KanbanAutomation $automation ): void
    {
        $shipment = $order->shipments()->first();

        expect( $shipment )->not->toBeNull()
            ->and( $shipment->label_id )->toBe( 4242 )
            ->and( $shipment->tracking_number )->toBe( 'TRACK-' . $shipment->id );
    }
}
