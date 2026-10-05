<?php

/**
 * KanbanAutomationTriggered event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\KanbanAutomationRunner::run()}
 * after an automation's trigger ran without throwing. Engine spec §7
 * event #37; parent plan §9.4.
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

use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanAutomationTriggered implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  KanbanAutomation  $automation  The automation that fired.
     * @param  Order             $order       The order whose card moved.
     */
    public function __construct(
        public readonly KanbanAutomation $automation,
        public readonly Order $order,
    ) {
    }
}
