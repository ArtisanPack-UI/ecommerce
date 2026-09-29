<?php

/**
 * ShipmentItemResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\ShipmentItem}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.shipmentItem`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Resources;

use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property \ArtisanPackUI\Ecommerce\Models\ShipmentItem $resource
 */
class ShipmentItemResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'shipmentItem';

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return array<string, mixed>
     */
    protected function fields( Request $request ): array
    {
        return [
            'shipment_id'   => $this->resource->shipment_id,
            'order_item_id' => $this->resource->order_item_id,
            'quantity'      => $this->resource->quantity,
        ];
    }
}
