<?php

/**
 * ProductPriceRequest.
 *
 * Body of `POST admin/products/{product}/prices` (upsert one row, for the
 * product or, with `product_variant_id`, one of its variants) and
 * `PATCH admin/products/{product}/prices/{price}`.
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
class ProductPriceRequest extends ApiFormRequest
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
            'currency'           => [ 'string', 'size:3' ],
            'price_amount'       => [ 'integer', 'min:0' ],
            'compare_at_amount'  => [ 'nullable', 'integer', 'min:0' ],
            'cost_amount'        => [ 'nullable', 'integer', 'min:0' ],
            'starts_at'          => [ 'nullable', 'date' ],
            'ends_at'            => [ 'nullable', 'date' ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->sometimes( self::baseRules(), [ 'currency', 'price_amount' ] );
    }
}
