<?php

/**
 * UpdateNotificationPreferencesRequest.
 *
 * Validates `PATCH me/notification-preferences` (engine spec §9.4): a
 * list of `{ channel, category, is_enabled }` changes.
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

use ArtisanPackUI\Ecommerce\Models\CustomerNotificationPreference;
use ArtisanPackUI\Ecommerce\Services\NotificationPreferenceService;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class UpdateNotificationPreferencesRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'preferences'              => [ 'required', 'array', 'min:1' ],
            'preferences.*.channel'    => [ 'required', 'string', Rule::in( app( NotificationPreferenceService::class )->channels() ) ],
            'preferences.*.category'   => [ 'required', 'string', Rule::in( CustomerNotificationPreference::CATEGORIES ) ],
            'preferences.*.is_enabled' => [ 'required', 'boolean' ],
        ];
    }
}
