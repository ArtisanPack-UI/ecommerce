<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger;
use ArtisanPackUI\Ecommerce\Jobs\SendKanbanAutomationWebhookJob;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\KanbanAutomationTriggerContractTest;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookUrlGuard;
use Illuminate\Support\Facades\Bus;

/**
 * Verifies the built-in `webhook` automation trigger satisfies the shared
 * {@see KanbanAutomationTriggerContractTest} suite.
 *
 * @since 1.0.0
 */
final class WebhookTriggerContractTest extends KanbanAutomationTriggerContractTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        WebhookUrlGuard::resolveUsing( static fn (): array => [ '93.184.216.34' ] );
    }

    protected function tearDown(): void
    {
        WebhookUrlGuard::resolveUsing( null );

        parent::tearDown();
    }

    protected function trigger(): KanbanAutomationTrigger
    {
        return $this->app->make( KanbanAutomationRegistry::class )->get( 'webhook' );
    }

    protected function validConfig(): array
    {
        return [ 'url' => 'https://hooks.example.test/kanban', 'secret' => 'shh' ];
    }

    protected function invalidConfig(): array
    {
        return [ 'url' => 'http://hooks.example.test/kanban' ];
    }

    protected function assertFired( Order $order, KanbanAutomation $automation ): void
    {
        Bus::assertDispatched( SendKanbanAutomationWebhookJob::class, fn ( SendKanbanAutomationWebhookJob $job ): bool => 'https://hooks.example.test/kanban' === $job->url
            && 'shh' === $job->secret
            && $job->payload['order']['id'] === $order->id
            && $job->payload['automation_id'] === $automation->id );
    }
}
