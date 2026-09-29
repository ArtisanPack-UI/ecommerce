<?php

/**
 * ProductResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\Product}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.product`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\Product $resource
 */
class ProductResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'product';

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
            'product_type'            => $this->resource->type,
            'name'                    => $this->resource->name,
            'slug'                    => $this->resource->slug,
            'sku'                     => $this->resource->sku,
            'barcode'                 => $this->resource->barcode,
            'description'             => $this->resource->description,
            'short_description'       => $this->resource->short_description,
            'status'                  => $this->resource->status,
            'featured_image_media_id' => $this->resource->featured_image_media_id,
            'is_taxable'              => $this->resource->is_taxable,
            'tax_class_key'           => $this->resource->tax_class_key,
            'weight'                  => $this->resource->weight,
            'weight_unit'             => $this->resource->weight_unit,
            'length'                  => $this->resource->length,
            'width'                   => $this->resource->width,
            'height'                  => $this->resource->height,
            'dim_unit'                => $this->resource->dim_unit,
            'avg_rating'              => $this->resource->avg_rating,
            'reviews_count'           => $this->resource->reviews_count,
            'meta'                    => $this->adminOnly( $request, $this->resource->meta ),
            'published_at'            => $this->resource->published_at,
            'created_at'              => $this->resource->created_at,
            'updated_at'              => $this->resource->updated_at,
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
            'variants'   => [ 'variants', ProductVariantResource::class ],
            'prices'     => [ 'prices', ProductPriceResource::class ],
            'attributes' => [ 'productAttributes', ProductAttributeResource::class ],
        ];
    }
}
