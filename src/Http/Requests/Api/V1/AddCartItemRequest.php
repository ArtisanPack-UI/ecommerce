<?php

/**
 * AddCartItemRequest.
 *
 * Payload for `POST carts/{cart}/items` (engine spec §9.2). The unit price
 * is never accepted from the client — it is resolved server-side.
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
class AddCartItemRequest extends ApiFormRequest
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
            'product_id'         => [ 'required', 'integer', 'min:1' ],
            'product_variant_id' => [ 'nullable', 'integer', 'min:1' ],
            'quantity'           => [ 'required', 'integer', 'min:1', 'max:10000' ],
            'options'            => [ 'nullable', 'array' ],
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
