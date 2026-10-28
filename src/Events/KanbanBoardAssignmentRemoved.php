<?php

/**
 * KanbanBoardAssignmentRemoved event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\KanbanRoutingService}
 * after an assignment's `removed_at` is set. Engine spec §7 event #39;
 * parent plan §9.2.
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

use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanBoardAssignmentRemoved implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     * @since 1.2.0 Carries the acting user.
     *
     * @param  OrderBoardAssignment  $assignment   The removed assignment.
     * @param  int|null              $actorUserId  Acting user; null for routing.
     */
    public function __construct(
        public readonly OrderBoardAssignment $assignment,
        public readonly ?int $actorUserId = null,
    ) {
    }
}
