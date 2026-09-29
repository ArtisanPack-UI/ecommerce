<?php

/**
 * CartResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\Cart}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.cart`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\Cart $resource
 */
class CartResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'cart';

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
            'token'               => $this->resource->token,
            'customer_id'         => $this->resource->customer_id,
            'currency'            => $this->resource->currency,
            'email'               => $this->resource->email,
            'subtotal'            => $this->money( 'subtotal_amount' ),
            'discount'            => $this->money( 'discount_amount' ),
            'tax'                 => $this->money( 'tax_amount' ),
            'shipping'            => $this->money( 'shipping_amount' ),
            'total'               => $this->money( 'total_amount' ),
            'checkout_started_at' => $this->resource->checkout_started_at,
            'abandoned_at'        => $this->resource->abandoned_at,
            'completed_order_id'  => $this->resource->completed_order_id,
            'meta'                => $this->resource->meta,
            'expires_at'          => $this->resource->expires_at,
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
            'items'    => [ 'items', CartItemResource::class ],
            'customer' => [ 'customer', CustomerResource::class ],
        ];
    }
}
