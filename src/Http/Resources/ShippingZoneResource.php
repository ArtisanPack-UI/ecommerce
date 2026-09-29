<?php

/**
 * ShippingZoneResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\ShippingZone}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.shippingZone`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\ShippingZone $resource
 */
class ShippingZoneResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'shippingZone';

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
            'name'            => $this->resource->name,
            'country_codes'   => $this->resource->country_codes,
            'region_codes'    => $this->resource->region_codes,
            'postal_patterns' => $this->resource->postal_patterns,
            'priority'        => $this->resource->priority,
            'is_active'       => $this->resource->is_active,
            'created_at'      => $this->resource->created_at,
            'updated_at'      => $this->resource->updated_at,
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
            'methods' => [ 'methods', ShippingMethodResource::class ],
        ];
    }
}
