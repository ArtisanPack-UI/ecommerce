<?php

/**
 * ShippingZoneRequest.
 *
 * Payload for `POST admin/shipping-zones` / `PATCH admin/shipping-zones/{zone}`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Requests\Api\V1;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ShippingZoneRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->sometimes( [
            'name'              => [ 'string', 'max:255' ],
            'country_codes'     => [ 'array', 'min:1' ],
            'country_codes.*'   => [ 'string', 'size:2' ],
            'region_codes'      => [ 'nullable', 'array' ],
            'region_codes.*'    => [ 'string', 'max:10' ],
            'postal_patterns'   => [ 'nullable', 'array' ],
            'postal_patterns.*' => [ 'string', 'max:60' ],
            'priority'          => [ 'integer' ],
            'is_active'         => [ 'boolean' ],
        ], [ 'name', 'country_codes' ] );
    }
}
