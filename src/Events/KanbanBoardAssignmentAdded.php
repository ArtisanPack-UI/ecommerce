<?php

/**
 * KanbanBoardAssignmentAdded event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\KanbanRoutingService}
 * when an order is placed on a board — by routing at placement, re-routing
 * after an edit, or a manual assignment. Engine spec §7 event #38; parent
 * plan §9.2.
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

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanBoardAssignmentAdded
{
    /**
     * @since 1.0.0
     *
     * @param  OrderBoardAssignment  $assignment  The new (or re-activated) assignment.
     */
    public function __construct(
        public readonly OrderBoardAssignment $assignment,
    ) {
    }
}
