<?php

/**
 * ProductImageResource.
 *
 * One image in a product's ordered gallery (engine spec §3.10).
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
 */
class ProductImageResource extends EcommerceResource
{
    /**
     * Resource type name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'productImage';

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
            'product_id' => $this->resource->product_id,
            'media_id'   => $this->resource->media_id,
            'image_url'  => $this->resource->image_url,
            'alt_text'   => $this->resource->alt_text,
            'position'   => $this->resource->position,
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
        ];
    }
}
