<?php

/**
 * UpdateNotificationTemplateRequest.
 *
 * Validates `PATCH admin/notification-templates/{template}` (engine spec
 * §9.11). Twig sources are checked against the sandbox and the
 * template's declared variables by the service, not here.
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
class UpdateNotificationTemplateRequest extends ApiFormRequest
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
            'subject'      => [ 'sometimes', 'nullable', 'string', 'max:2000' ],
            'body'         => [ 'sometimes', 'string', 'max:100000' ],
            'is_active'    => [ 'sometimes', 'boolean' ],
            'preview_data' => [ 'sometimes', 'array' ],
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
