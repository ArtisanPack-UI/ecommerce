<?php

/**
 * ProductVariantResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\ProductVariant}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.variant`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\ProductVariant $resource
 */
class ProductVariantResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'variant';

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
            'product_id'     => $this->resource->product_id,
            'sku'            => $this->resource->sku,
            'barcode'        => $this->resource->barcode,
            'name'           => $this->resource->name,
            'image_media_id' => $this->resource->image_media_id,
            'weight'         => $this->resource->weight,
            'weight_unit'    => $this->resource->weight_unit,
            'length'         => $this->resource->length,
            'width'          => $this->resource->width,
            'height'         => $this->resource->height,
            'dim_unit'       => $this->resource->dim_unit,
            'position'       => $this->resource->position,
            'meta'           => $this->adminOnly( $request, $this->resource->meta ),
            'created_at'     => $this->resource->created_at,
            'updated_at'     => $this->resource->updated_at,
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
            'prices'  => [ 'prices', ProductPriceResource::class ],
        ];
    }
}
