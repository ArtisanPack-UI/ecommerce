<?php

/**
 * ValidateLicenseRequest.
 *
 * Validates `POST license/validate` (engine spec §9.9): the license `key`
 * and the calling machine's `fingerprint`.
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
class ValidateLicenseRequest extends ApiFormRequest
{
    /**
     * Rules shared with the GraphQL mutation.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, string>>
     */
    public static function baseRules(): array
    {
        return [
            'key'         => [ 'required', 'string', 'max:255' ],
            'fingerprint' => [ 'required', 'string', 'max:128' ],
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
