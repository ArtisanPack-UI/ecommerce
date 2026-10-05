<?php

/**
 * KanbanCardMoved event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\KanbanBoardService::move()}
 * after a card's column change (and any resulting order status rollup) has
 * committed. Drives kanban automations and the per-board broadcast.
 * Engine spec §7 event #36; parent plan §9.4.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Events;

use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanCardMoved implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  Order         $order  The refreshed order.
     * @param  KanbanColumn  $from   Column the card left.
     * @param  KanbanColumn  $to     Column the card entered.
     * @param  KanbanBoard   $board  Board the move happened on.
     */
    public function __construct(
        public readonly Order $order,
        public readonly KanbanColumn $from,
        public readonly KanbanColumn $to,
        public readonly KanbanBoard $board,
    ) {
    }
}
