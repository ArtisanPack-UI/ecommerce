<?php

/**
 * UpdateSettingsRequest.
 *
 * Validates `PATCH admin/settings/{group}` (engine issue #145). Only the
 * envelope is checked here; each value is validated against its
 * definition by {@see \ArtisanPackUI\Ecommerce\Settings\SettingsRepository::update()}.
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
class UpdateSettingsRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'values'                       => [ 'present', 'array' ],
            'reset'                        => [ 'sometimes', 'array' ],
            'reset.*'                      => [ 'string', 'max:191' ],
            'confirm_base_currency_change' => [ 'sometimes', 'boolean' ],
        ];
    }
}
