<?php

/**
 * UpdateCustomerRequest.
 *
 * Payload for `PATCH customers/{customer}`.
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
class UpdateCustomerRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name'        => [ 'sometimes', 'nullable', 'string', 'max:120' ],
            'last_name'         => [ 'sometimes', 'nullable', 'string', 'max:120' ],
            'phone'             => [ 'sometimes', 'nullable', 'string', 'max:50' ],
            'accepts_marketing' => [ 'sometimes', 'boolean' ],
            'meta'              => [ 'sometimes', 'array' ],
        ];
    }
}
