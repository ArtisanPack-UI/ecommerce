<?php

/**
 * OrderItemResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\OrderItem}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.orderItem`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\OrderItem $resource
 */
class OrderItemResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'orderItem';

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
            'order_id'           => $this->resource->order_id,
            'product_id'         => $this->resource->product_id,
            'product_variant_id' => $this->resource->product_variant_id,
            'product_snapshot'   => $this->resource->product_snapshot,
            'quantity'           => $this->resource->quantity,
            'unit_price'         => $this->money( 'unit_price_amount' ),
            'discount'           => $this->money( 'discount_amount' ),
            'tax'                => $this->money( 'tax_amount' ),
            'shipping'           => $this->money( 'shipping_amount' ),
            'total'              => $this->money( 'total_amount' ),
            'fulfillment_status' => $this->resource->fulfillment_status,
            'meta'               => $this->resource->meta,
        ];
    }
}
