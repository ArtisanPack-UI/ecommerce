<?php

/**
 * CancelOrderRequest.
 *
 * Payload for `POST orders/{order}/cancel` (engine spec §9.3); see
 * {@see \ArtisanPackUI\Ecommerce\Services\OrderCancellationService::cancel()}.
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
class CancelOrderRequest extends ApiFormRequest
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
            'reason' => [ 'required', 'string', 'max:500' ],
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
