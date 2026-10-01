<?php

/**
 * ProductChildResource.
 *
 * One member of a grouped or bundled product (engine spec §3.10a).
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
class ProductChildResource extends EcommerceResource
{
    /**
     * Resource type name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'productChild';

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
            'parent_product_id' => $this->resource->parent_product_id,
            'child_product_id'  => $this->resource->child_product_id,
            'child_variant_id'  => $this->resource->child_variant_id,
            'quantity'          => $this->resource->quantity,
            'position'          => $this->resource->position,
            'created_at'        => $this->resource->created_at,
            'updated_at'        => $this->resource->updated_at,
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
