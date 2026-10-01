<?php

/**
 * AddOrderNoteRequest.
 *
 * Payload for `POST orders/{order}/notes` (engine spec §9.3); see
 * {@see \ArtisanPackUI\Ecommerce\Services\OrderNoteService::add()}.
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

use ArtisanPackUI\Ecommerce\Services\OrderNoteService;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class AddOrderNoteRequest extends ApiFormRequest
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
            'body'                => [ 'required', 'string', 'max:' . OrderNoteService::MAX_BODY_LENGTH ],
            'is_customer_visible' => [ 'boolean' ],
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
