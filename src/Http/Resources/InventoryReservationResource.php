<?php

/**
 * InventoryReservationResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\InventoryReservation}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.inventoryReservation`.
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

use ArtisanPackUI\Ecommerce\Support\MorphType;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property \ArtisanPackUI\Ecommerce\Models\InventoryReservation $resource
 */
class InventoryReservationResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'inventoryReservation';

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
            'inventory_item_id' => $this->resource->inventory_item_id,
            'reservable_type'   => MorphType::basename( $this->resource->reservable_type ),
            'reservable_id'     => $this->resource->reservable_id,
            'quantity'          => $this->resource->quantity,
            'expires_at'        => $this->resource->expires_at,
        ];
    }
}
