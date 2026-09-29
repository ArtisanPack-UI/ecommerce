<?php

/**
 * CartItemResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\CartItem}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.cartItem`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\CartItem $resource
 */
class CartItemResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'cartItem';

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
            'cart_id'            => $this->resource->cart_id,
            'product_id'         => $this->resource->product_id,
            'product_variant_id' => $this->resource->product_variant_id,
            'quantity'           => $this->resource->quantity,
            'unit_price'         => $this->money( 'unit_price_amount' ),
            'line_subtotal'      => $this->money( 'line_subtotal_amount' ),
            'line_total'         => $this->money( 'line_total_amount' ),
            'options'            => $this->resource->options,
            'meta'               => $this->resource->meta,
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
            'product' => [ 'product', ProductResource::class ],
            'variant' => [ 'variant', ProductVariantResource::class ],
        ];
    }
}
