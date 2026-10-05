<?php

/**
 * ShippingDestinationRequest.
 *
 * Query for `GET carts/{cart}/shipping-rates` (audit F4): where to quote
 * shipping to. A country is enough for an estimate; region and postal code
 * narrow zones that need them.
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
class ShippingDestinationRequest extends ApiFormRequest
{
    /**
     * Rules shared with the matching GraphQL field.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public static function baseRules(): array
    {
        return [
            'country_code' => [ 'required', 'string', 'size:2', 'alpha' ],
            'region_code'  => [ 'nullable', 'string', 'max:10' ],
            'postal_code'  => [ 'nullable', 'string', 'max:20' ],
            'city'         => [ 'nullable', 'string', 'max:255' ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::baseRules();
    }
}
