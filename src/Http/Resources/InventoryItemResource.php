<?php

/**
 * InventoryItemResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\InventoryItem}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.inventoryItem`.
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

use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Support\MorphType;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property \ArtisanPackUI\Ecommerce\Models\InventoryItem $resource
 */
class InventoryItemResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'inventoryItem';

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
            'stockable_type'      => MorphType::is( $this->resource->stockable_type, ProductVariant::class ) ? 'variant' : 'product',
            'stockable_id'        => $this->resource->stockable_id,
            'track_inventory'     => $this->resource->track_inventory,
            'quantity_on_hand'    => $this->resource->quantity_on_hand,
            'quantity_reserved'   => $this->resource->quantity_reserved,
            'quantity_available'  => (int) $this->resource->quantity_on_hand - (int) $this->resource->quantity_reserved,
            'allow_backorder'     => $this->resource->allow_backorder,
            'low_stock_threshold' => $this->resource->low_stock_threshold,
            'warehouse_id'        => $this->resource->warehouse_id,
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array{0: string, 1: class-string<EcommerceResource>}>
     */
    protected function relations(): array
    {
        return [
            'reservations' => [ 'reservations', InventoryReservationResource::class ],
        ];
    }
}
