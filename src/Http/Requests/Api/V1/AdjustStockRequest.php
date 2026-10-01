<?php

/**
 * AdjustStockRequest.
 *
 * Body of `POST admin/products/{product}/stock`: an audited stock change
 * for the product or, with `product_variant_id`, one of its variants.
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
class AdjustStockRequest extends ApiFormRequest
{
    /**
     * Shape rules shared by REST and GraphQL.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public static function baseRules(): array
    {
        return [
            'product_variant_id' => [ 'nullable', 'integer' ],
            'delta'              => [ 'required', 'integer', 'not_in:0' ],
            'reason'             => [ 'required', 'string', 'max:255' ],
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
