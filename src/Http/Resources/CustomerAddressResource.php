<?php

/**
 * CustomerAddressResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\CustomerAddress}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.customerAddress`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\CustomerAddress $resource
 */
class CustomerAddressResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'customerAddress';

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
            'customer_id'         => $this->resource->customer_id,
            'label'               => $this->resource->label,
            'is_default_shipping' => $this->resource->is_default_shipping,
            'is_default_billing'  => $this->resource->is_default_billing,
            'first_name'          => $this->resource->first_name,
            'last_name'           => $this->resource->last_name,
            'company'             => $this->resource->company,
            'phone'               => $this->resource->phone,
            'address1'            => $this->resource->address1,
            'address2'            => $this->resource->address2,
            'city'                => $this->resource->city,
            'region'              => $this->resource->region,
            'region_code'         => $this->resource->region_code,
            'postal_code'         => $this->resource->postal_code,
            'country_code'        => $this->resource->country_code,
        ];
    }
}
