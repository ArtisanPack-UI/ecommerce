<?php

/**
 * OrderResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\Order}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.order`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\Order $resource
 */
class OrderResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'order';

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
            'order_number'        => $this->resource->order_number,
            'customer_id'         => $this->resource->customer_id,
            'email'               => $this->resource->email,
            'phone'               => $this->resource->phone,
            'system_status'       => $this->resource->system_status,
            'substatus_id'        => $this->resource->substatus_id,
            'payment_status'      => $this->resource->payment_status,
            'fulfillment_status'  => $this->resource->fulfillment_status,
            'currency'            => $this->resource->currency,
            'base_currency'       => $this->resource->base_currency,
            'fx_rate_to_base_e8'  => $this->resource->fx_rate_to_base_e8,
            'subtotal'            => $this->money( 'subtotal_amount' ),
            'discount'            => $this->money( 'discount_amount' ),
            'tax'                 => $this->money( 'tax_amount' ),
            'shipping'            => $this->money( 'shipping_amount' ),
            'total'               => $this->money( 'total_amount' ),
            'total_refunded'      => $this->money( 'total_refunded_amount' ),
            'shipping_address'    => $this->resource->shipping_address,
            'billing_address'     => $this->resource->billing_address,
            'shipping_method_key' => $this->resource->shipping_method_key,
            'payment_gateway_key' => $this->resource->payment_gateway_key,
            'payment_reference'   => $this->adminOnly( $request, $this->resource->payment_reference ),
            'ip_address'          => $this->adminOnly( $request, $this->resource->ip_address ),
            'user_agent'          => $this->adminOnly( $request, $this->resource->user_agent ),
            'customer_note'       => $this->resource->customer_note,
            'is_claimed'          => $this->resource->is_claimed,
            'meta'                => $this->resource->meta,
            'placed_at'           => $this->resource->placed_at,
            'created_at'          => $this->resource->created_at,
            'updated_at'          => $this->resource->updated_at,
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
            'items'            => [ 'items', OrderItemResource::class ],
            'customer'         => [ 'customer', CustomerResource::class ],
            'notes'            => [ 'notes', OrderNoteResource::class ],
            'timeline'         => [ 'timelineEntries', OrderTimelineEntryResource::class ],
            'edits'            => [ 'edits', OrderEditResource::class ],
            'refunds'          => [ 'refunds', RefundResource::class ],
            'shipments'        => [ 'shipments', ShipmentResource::class ],
            'promotion_usages' => [ 'promotionUsages', PromotionUsageResource::class ],
        ];
    }
}
