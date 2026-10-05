<?php

/**
 * CheckoutShippingMethodRequest.
 *
 * Payload for `POST checkout/{cart}/shipping-method` (engine spec §9.2): the
 * id of one of the shipping rates quoted for the cart.
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
class CheckoutShippingMethodRequest extends ApiFormRequest
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
            'rate_id' => [ 'required', 'string', 'max:255' ],
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
