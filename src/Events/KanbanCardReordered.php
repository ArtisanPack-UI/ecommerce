<?php

/**
 * KanbanCardReordered event.
 *
 * Dispatched after commit when a card changes place inside its column
 * (ArtisanPack-UI/ecommerce#198). A reorder changes no status and runs no
 * automations; it only changes the order cards are shown in.
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

use ArtisanPackUI\Ecommerce\Kanban\KanbanCardPlacement;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
class KanbanCardReordered implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.2.0
     *
     * @param  Order                $order      The order whose card moved.
     * @param  KanbanColumn         $column     The column it moved in.
     * @param  KanbanBoard          $board      The board.
     * @param  KanbanCardPlacement  $placement  Where it was placed.
     */
    public function __construct(
        public readonly Order $order,
        public readonly KanbanColumn $column,
        public readonly KanbanBoard $board,
        public readonly KanbanCardPlacement $placement,
    ) {
    }
}
