<?php

/**
 * UpdateCartRequest.
 *
 * Payload for `PATCH carts/{cart}` (audit F4): the cart's email and
 * shipping/billing addresses (null clears one).
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
class UpdateCartRequest extends ApiFormRequest
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
            'email'            => [ 'sometimes', 'nullable', 'string', 'email', 'max:255' ],
            'shipping_address' => [ 'sometimes', 'nullable', 'array' ],
            'billing_address'  => [ 'sometimes', 'nullable', 'array' ],
            ...CheckoutAddressRequest::addressRules( 'shipping_address' ),
            ...CheckoutAddressRequest::addressRules( 'billing_address' ),
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
