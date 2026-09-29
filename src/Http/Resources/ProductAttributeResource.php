<?php

/**
 * ProductAttributeResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\ProductAttribute}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.productAttribute`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\ProductAttribute $resource
 */
class ProductAttributeResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'productAttribute';

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
            'product_id'   => $this->resource->product_id,
            'key'          => $this->resource->key,
            'label'        => $this->resource->label,
            'position'     => $this->resource->position,
            'is_variation' => $this->resource->is_variation,
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
            'values' => [ 'values', ProductAttributeValueResource::class ],
        ];
    }
}
