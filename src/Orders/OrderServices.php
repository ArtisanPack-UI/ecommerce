<?php

/**
 * OrderServices.
 *
 * The order-side services in one place, returned by
 * {@see \ArtisanPackUI\Ecommerce\Ecommerce::orders()} (audit I4):
 * placement, status transitions, edits, cancellation, refunds, shipments,
 * and notes.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Orders;

use ArtisanPackUI\Ecommerce\Services\OrderCancellationService;
use ArtisanPackUI\Ecommerce\Services\OrderEditService;
use ArtisanPackUI\Ecommerce\Services\OrderNoteService;
use ArtisanPackUI\Ecommerce\Services\OrderPlacementService;
use ArtisanPackUI\Ecommerce\Services\OrderStatusMachine;
use ArtisanPackUI\Ecommerce\Services\RefundService;
use ArtisanPackUI\Ecommerce\Services\ShipmentService;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class OrderServices
{
    /**
     * @since 1.0.0
     *
     * @param  OrderPlacementService     $placement     Turns a ready cart into an order.
     * @param  OrderStatusMachine        $status        Moves orders between statuses.
     * @param  OrderEditService          $edits         Edits placed orders.
     * @param  OrderCancellationService  $cancellation  Cancels orders.
     * @param  RefundService             $refunds       Issues refunds.
     * @param  ShipmentService           $shipments     Ships and tracks orders.
     * @param  OrderNoteService          $notes         Adds order notes.
     */
    public function __construct(
        public readonly OrderPlacementService $placement,
        public readonly OrderStatusMachine $status,
        public readonly OrderEditService $edits,
        public readonly OrderCancellationService $cancellation,
        public readonly RefundService $refunds,
        public readonly ShipmentService $shipments,
        public readonly OrderNoteService $notes,
    ) {
    }
}
