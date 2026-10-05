<?php

/**
 * DeactivateLicenseRequest.
 *
 * `POST license/deactivate` (audit I2): the key and the machine whose
 * activation to free. Same rules as {@see ValidateLicenseRequest}.
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
class DeactivateLicenseRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ValidateLicenseRequest::baseRules();
    }
}
