<?php

/**
 * ProductAttributeValueResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\ProductAttributeValue}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.productAttributeValue`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\ProductAttributeValue $resource
 */
class ProductAttributeValueResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'productAttributeValue';

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
            'product_attribute_id' => $this->resource->product_attribute_id,
            'value'                => $this->resource->value,
            'label'                => $this->resource->label,
            'swatch'               => $this->resource->swatch,
            'position'             => $this->resource->position,
        ];
    }
}
