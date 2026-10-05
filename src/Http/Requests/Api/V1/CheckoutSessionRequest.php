<?php

/**
 * CheckoutSessionRequest.
 *
 * Payload for `POST checkout/{cart}/session` (engine spec §9.2). `return_url`
 * is where the provider sends the shopper back after a redirect or 3DS
 * step; it must be on this site.
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
class CheckoutSessionRequest extends ApiFormRequest
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
            'return_url' => [ 'nullable', 'string', 'url', 'max:2000' ],
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
