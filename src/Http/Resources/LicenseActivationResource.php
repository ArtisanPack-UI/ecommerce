<?php

/**
 * LicenseActivationResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\LicenseActivation}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.licenseActivation`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\LicenseActivation $resource
 */
class LicenseActivationResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'licenseActivation';

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
            'license_key_id'      => $this->resource->license_key_id,
            'machine_fingerprint' => $this->resource->machine_fingerprint,
            'activated_at'        => $this->resource->activated_at,
            'last_seen_at'        => $this->resource->last_seen_at,
            'ip_address'          => $this->resource->ip_address,
        ];
    }
}
