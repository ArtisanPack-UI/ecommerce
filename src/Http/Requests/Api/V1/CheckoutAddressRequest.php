<?php

/**
 * CheckoutAddressRequest.
 *
 * Payload for `POST checkout/{cart}/address` (engine spec §9.2): the
 * shopper's email and their shipping and/or billing address. Leave
 * `billing_address` out to bill to the shipping address.
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
class CheckoutAddressRequest extends ApiFormRequest
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
            'email'            => [ 'nullable', 'string', 'email', 'max:255' ],
            'shipping_address' => [ 'nullable', 'array', 'required_without:billing_address' ],
            'billing_address'  => [ 'nullable', 'array' ],
            ...self::addressRules( 'shipping_address' ),
            ...self::addressRules( 'billing_address' ),
        ];
    }

    /**
     * Rules for the fields of one address.
     *
     * @since 1.0.0
     *
     * @param  string  $field  Address field.
     *
     * @return array<string, array<int, string>>
     */
    public static function addressRules( string $field ): array
    {
        return [
            $field . '.first_name'   => [ 'nullable', 'string', 'max:255' ],
            $field . '.last_name'    => [ 'nullable', 'string', 'max:255' ],
            $field . '.company'      => [ 'nullable', 'string', 'max:255' ],
            $field . '.phone'        => [ 'nullable', 'string', 'max:50' ],
            $field . '.address1'     => [ 'required_with:' . $field, 'string', 'max:255' ],
            $field . '.address2'     => [ 'nullable', 'string', 'max:255' ],
            $field . '.city'         => [ 'required_with:' . $field, 'string', 'max:255' ],
            $field . '.region'       => [ 'nullable', 'string', 'max:255' ],
            $field . '.region_code'  => [ 'nullable', 'string', 'max:10' ],
            $field . '.postal_code'  => [ 'nullable', 'string', 'max:20' ],
            $field . '.country_code' => [ 'required_with:' . $field, 'string', 'size:2', 'alpha' ],
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
