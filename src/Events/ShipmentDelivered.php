<?php

/**
 * ShipmentDelivered event.
 *
 * A shipment was delivered — by tracking, on creation, or at local pickup (webhook `shipment.delivered`).
 * Dispatched after the surrounding transaction commits (audit I1).
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
use ArtisanPackUI\Ecommerce\Models\Shipment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ShipmentDelivered implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  Shipment  $shipment  The delivered shipment.
     * @param  Order     $order     Its order.
     */
    public function __construct(
        public readonly Shipment $shipment,
        public readonly Order $order,
    ) {
    }
}
