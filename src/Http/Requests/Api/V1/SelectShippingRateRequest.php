<?php

/**
 * SelectShippingRateRequest.
 *
 * Payload for `PUT carts/{cart}/shipping-rate` (audit F4): a rate id from
 * `GET carts/{cart}/shipping-rates` and the destination it was quoted for.
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
class SelectShippingRateRequest extends ApiFormRequest
{
    /**
     * Rules shared with the matching GraphQL mutation.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public static function baseRules(): array
    {
        return [
            'rate_id'                  => [ 'required', 'string', 'max:255' ],
            'destination'              => [ 'required', 'array' ],
            'destination.country_code' => [ 'required', 'string', 'size:2', 'alpha' ],
            'destination.region_code'  => [ 'nullable', 'string', 'max:10' ],
            'destination.postal_code'  => [ 'nullable', 'string', 'max:20' ],
            'destination.city'         => [ 'nullable', 'string', 'max:255' ],
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
