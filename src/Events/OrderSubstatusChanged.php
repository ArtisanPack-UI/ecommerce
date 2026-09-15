<?php

/**
 * OrderSubstatusChanged event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\OrderStatusMachine::setSubstatus()}
 * after a `substatus_id` change has been persisted and the
 * `order.substatus_changed` timeline entry has been written. Engine spec §7
 * event #8; plan §5.7 / §9.2.
 *
 * The optional `$boardId` argument names the kanban board whose column drove
 * the change (per-board assignments live on `order_board_assignments` — engine
 * spec §3.35). `null` means the change came from the order's global default
 * sub-status, not from a board.
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

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderSubstatusChanged
{
    /**
     * @since 1.0.0
     *
     * @param  Order               $order    The refreshed order after the change.
     * @param  OrderSubstatus|null $from     The sub-status before the change, or `null` if it was unset.
     * @param  OrderSubstatus      $to       The sub-status after the change.
     * @param  int|null            $boardId  Kanban board id whose column drove this change, or `null` for global default.
     */
    public function __construct(
        public readonly Order $order,
        public readonly ?OrderSubstatus $from,
        public readonly OrderSubstatus $to,
        public readonly ?int $boardId = null,
    ) {
    }
}
