<?php

/**
 * CustomerAddressRequest.
 *
 * Payload for `POST customers/{customer}/addresses` and
 * `PATCH customers/{customer}/addresses/{address}`; see
 * {@see \ArtisanPackUI\Ecommerce\Services\CustomerAddressService}. On PATCH
 * every key is optional.
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

use ArtisanPackUI\Ecommerce\Services\CustomerAddressService;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerAddressRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ( CustomerAddressService::TEXT_FIELDS as $field => $max ) {
            $rules[ $field ] = in_array( $field, CustomerAddressService::REQUIRED_FIELDS, true )
                ? [ 'string', 'max:' . $max ]
                : [ 'nullable', 'string', 'max:' . $max ];
        }

        $rules['country_code'][] = 'size:2';

        foreach ( CustomerAddressService::FLAG_FIELDS as $field ) {
            $rules[ $field ] = [ 'boolean' ];
        }

        return $this->sometimes( $rules, CustomerAddressService::REQUIRED_FIELDS );
    }
}
