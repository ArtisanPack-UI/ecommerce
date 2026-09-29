<?php

/**
 * ModerateReviewRequest.
 *
 * Validates `POST admin/reviews/{review}/moderate`: an `action`
 * (`approve`, `reject`, `spam`, `pending`) and an optional rejection
 * `reason`.
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

use ArtisanPackUI\Ecommerce\Services\ReviewService;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ModerateReviewRequest extends ApiFormRequest
{
    /**
     * Rules shared with the GraphQL mutation.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public static function baseRules(): array
    {
        return [
            'action' => [ 'required', 'string', Rule::in( ReviewService::ACTIONS ) ],
            'reason' => [ 'nullable', 'string', 'max:1000' ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return self::baseRules();
    }
}
