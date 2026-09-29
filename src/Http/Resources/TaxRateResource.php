<?php

/**
 * TaxRateResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\TaxRate}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.taxRate`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\TaxRate $resource
 */
class TaxRateResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'taxRate';

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
            'tax_class_key'       => $this->resource->tax_class_key,
            'country_code'        => $this->resource->country_code,
            'region_code'         => $this->resource->region_code,
            'postal_pattern'      => $this->resource->postal_pattern,
            'rate_ubps'           => $this->resource->rate_ubps,
            'rate_percent'        => $this->resource->percent(),
            'is_compound'         => $this->resource->is_compound,
            'priority'            => $this->resource->priority,
            'label'               => $this->resource->label,
            'is_shipping_taxable' => $this->resource->is_shipping_taxable,
            'is_active'           => $this->resource->is_active,
            'created_at'          => $this->resource->created_at,
            'updated_at'          => $this->resource->updated_at,
        ];
    }
}
