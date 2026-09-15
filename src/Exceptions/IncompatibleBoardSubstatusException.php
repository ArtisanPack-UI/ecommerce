<?php

/**
 * IncompatibleBoardSubstatusException.
 *
 * Thrown at the service boundary when a caller tries to place an order on a
 * kanban board using a sub-status whose {@see \ArtisanPackUI\Ecommerce\Models\OrderSubstatus::$system_status}
 * does not match the order's current `orders.system_status`. The two-tier
 * status machine keeps the layers consistent — a board assignment cannot pull
 * the order onto a sub-status that would contradict its system status. Plan
 * §5.7 / §9.2.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Exceptions;

use RuntimeException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class IncompatibleBoardSubstatusException extends RuntimeException
{
    /**
     * The order's current `system_status`.
     *
     * @since 1.0.0
     */
    public readonly string $orderSystemStatus;

    /**
     * The `system_status` the requested sub-status belongs to.
     *
     * @since 1.0.0
     */
    public readonly string $substatusSystemStatus;

    /**
     * The id of the sub-status that failed the compatibility check.
     *
     * @since 1.0.0
     */
    public readonly int $substatusId;

    /**
     * The id of the order the assignment targeted.
     *
     * @since 1.0.0
     */
    public readonly int $orderId;

    /**
     * @since 1.0.0
     *
     * @param  int     $orderId                Owning order id.
     * @param  string  $orderSystemStatus      Current `orders.system_status`.
     * @param  int     $substatusId            Rejected sub-status id.
     * @param  string  $substatusSystemStatus  Rejected sub-status's `system_status`.
     */
    public function __construct(
        int $orderId,
        string $orderSystemStatus,
        int $substatusId,
        string $substatusSystemStatus,
    ) {
        $this->orderId               = $orderId;
        $this->orderSystemStatus     = $orderSystemStatus;
        $this->substatusId           = $substatusId;
        $this->substatusSystemStatus = $substatusSystemStatus;

        parent::__construct( sprintf(
            'Order %d is on system_status "%s"; sub-status %d belongs to system_status "%s" and cannot be assigned.',
            $orderId,
            $orderSystemStatus,
            $substatusId,
            $substatusSystemStatus,
        ) );
    }
}
