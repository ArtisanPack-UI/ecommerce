<?php

/**
 * RevokeLicenseRequest.
 *
 * Validates `POST admin/license-keys/{key}/revoke` (engine spec §9.9): an
 * optional `reason`.
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
class RevokeLicenseRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => [ 'nullable', 'string', 'max:1000' ],
        ];
    }
}
