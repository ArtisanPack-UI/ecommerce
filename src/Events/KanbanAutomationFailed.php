<?php

/**
 * KanbanAutomationFailed event.
 *
 * Dispatched when a kanban automation's trigger throws or is no longer
 * registered (ArtisanPack-UI/ecommerce#200). The move that ran it stands;
 * the failure is logged and noted on the order timeline.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Events;

use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
class KanbanAutomationFailed implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.2.0
     *
     * @param  KanbanAutomation  $automation  The automation that failed.
     * @param  Order             $order       The order whose card moved.
     * @param  string            $error       Failure message.
     */
    public function __construct(
        public readonly KanbanAutomation $automation,
        public readonly Order $order,
        public readonly string $error,
    ) {
    }
}
