<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\KanbanAutomationTriggerContractTest;
use Illuminate\Support\Facades\Bus;
use stdClass;
use Tests\Fixtures\KanbanTestJob;

/**
 * Verifies the built-in `dispatch-job` automation trigger satisfies the shared
 * {@see KanbanAutomationTriggerContractTest} suite.
 *
 * @since 1.0.0
 */
final class DispatchJobTriggerContractTest extends KanbanAutomationTriggerContractTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        config()->set( 'artisanpack.ecommerce.kanban.dispatchable_jobs', [ KanbanTestJob::class ] );
    }

    protected function trigger(): KanbanAutomationTrigger
    {
        return $this->app->make( KanbanAutomationRegistry::class )->get( 'dispatch-job' );
    }

    protected function validConfig(): array
    {
        return [ 'job' => KanbanTestJob::class ];
    }

    protected function invalidConfig(): array
    {
        return [ 'job' => stdClass::class ];
    }

    protected function assertFired( Order $order, KanbanAutomation $automation ): void
    {
        Bus::assertDispatched( KanbanTestJob::class, fn ( KanbanTestJob $job ): bool => $job->orderId === (int) $order->id );
    }
}
