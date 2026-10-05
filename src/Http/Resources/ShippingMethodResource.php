<?php

/**
 * ShippingMethodResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\ShippingMethod}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.shippingMethod`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\ShippingMethod $resource
 */
class ShippingMethodResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'shippingMethod';

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
            'zone_id'       => $this->resource->zone_id,
            'key'           => $this->resource->key,
            'label'         => $this->resource->label,
            'config'        => $this->resource->config,
            'tax_class_key' => $this->resource->tax_class_key,
            'is_active'     => $this->resource->is_active,
            'position'      => $this->resource->position,
            'created_at'    => $this->resource->created_at,
            'updated_at'    => $this->resource->updated_at,
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
            'zone' => [ 'zone', ShippingZoneResource::class ],
        ];
    }
}
