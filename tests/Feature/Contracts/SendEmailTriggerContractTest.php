<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger;
use ArtisanPackUI\Ecommerce\Mail\KanbanAutomationMail;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\KanbanAutomationTriggerContractTest;
use Illuminate\Support\Facades\Mail;

/**
 * Verifies the built-in `send-email` automation trigger satisfies the shared
 * {@see KanbanAutomationTriggerContractTest} suite.
 *
 * @since 1.0.0
 */
final class SendEmailTriggerContractTest extends KanbanAutomationTriggerContractTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    protected function trigger(): KanbanAutomationTrigger
    {
        return $this->app->make( KanbanAutomationRegistry::class )->get( 'send-email' );
    }

    protected function validConfig(): array
    {
        return [ 'to' => 'customer', 'subject' => 'Order {order_number} moved to {column}', 'body' => 'Board: {board}' ];
    }

    protected function invalidConfig(): array
    {
        return [ 'to' => 'not-an-email', 'subject' => 'Hello' ];
    }

    protected function assertFired( Order $order, KanbanAutomation $automation ): void
    {
        Mail::assertQueued( KanbanAutomationMail::class, fn ( KanbanAutomationMail $mail ): bool => $mail->hasTo( $order->email )
            && str_contains( $mail->subjectLine, (string) $order->order_number )
            && str_contains( $mail->bodyText, (string) $automation->board->name ) );
    }
}
