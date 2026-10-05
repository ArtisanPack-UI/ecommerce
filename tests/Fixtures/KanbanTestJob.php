<?php

declare( strict_types=1 );

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Job dispatched by the `dispatch-job` kanban automation in tests.
 */
class KanbanTestJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct( public readonly int $orderId )
    {
    }

    public function handle(): void
    {
    }
}
