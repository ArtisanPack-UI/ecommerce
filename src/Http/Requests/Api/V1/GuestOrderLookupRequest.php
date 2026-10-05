<?php

/**
 * GuestOrderLookupRequest.
 *
 * `GET orders/guest-lookup` (#175): the email and order number.
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
class GuestOrderLookupRequest extends ApiFormRequest
{
    /**
     * Rules shared with the GraphQL query.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, string>>
     */
    public static function baseRules(): array
    {
        return [
            'email'        => [ 'required', 'string', 'email', 'max:255' ],
            'order_number' => [ 'required', 'string', 'max:50' ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return self::baseRules();
    }
}
