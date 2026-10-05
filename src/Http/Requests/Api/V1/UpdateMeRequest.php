<?php

/**
 * UpdateMeRequest.
 *
 * Payload for `PATCH me` (#173): the profile fields a shopper manages.
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

use ArtisanPackUI\Ecommerce\Support\SupportedLocales;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class UpdateMeRequest extends ApiFormRequest
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
            'first_name'        => [ 'sometimes', 'nullable', 'string', 'max:255' ],
            'last_name'         => [ 'sometimes', 'nullable', 'string', 'max:255' ],
            'phone'             => [ 'sometimes', 'nullable', 'string', 'max:50' ],
            'accepts_marketing' => [ 'sometimes', 'boolean' ],
            'locale'            => [ 'sometimes', 'nullable', 'string', Rule::in( SupportedLocales::all() ) ],
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
