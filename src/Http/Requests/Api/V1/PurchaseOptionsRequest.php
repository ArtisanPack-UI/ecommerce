<?php

/**
 * PurchaseOptionsRequest.
 *
 * `GET products/{product}/purchase-options` (#172): the currency to price
 * in and an optional destination for tax.
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
class PurchaseOptionsRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'currency'     => [ 'nullable', 'string', 'size:3', 'alpha' ],
            'country_code' => [ 'nullable', 'string', 'size:2', 'alpha' ],
            'region_code'  => [ 'nullable', 'string', 'max:10' ],
            'postal_code'  => [ 'nullable', 'string', 'max:20' ],
        ];
    }
}
