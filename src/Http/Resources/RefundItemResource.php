<?php

/**
 * RefundItemResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\RefundItem}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.refundItem`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\RefundItem $resource
 */
class RefundItemResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'refundItem';

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
            'refund_id'     => $this->resource->refund_id,
            'order_item_id' => $this->resource->order_item_id,
            'quantity'      => $this->resource->quantity,
            'amount'        => $this->money( 'amount', 'currency' ),
            'restock'       => $this->resource->restock,
        ];
    }
}
