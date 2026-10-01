<?php

/**
 * ProductVariantRequest.
 *
 * Body of `POST admin/products/{product}/variants` and
 * `PATCH admin/products/{product}/variants/{variant}` (engine spec §9.5).
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
class ProductVariantRequest extends ApiFormRequest
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
        return array_merge(
            [
                'sku'             => [ 'nullable', 'string', 'max:100' ],
                'barcode'         => [ 'nullable', 'string', 'max:100' ],
                'name'            => [ 'nullable', 'string', 'max:255' ],
                'image_media_id'  => [ 'nullable', 'integer', 'min:1' ],
                'weight'          => [ 'nullable', 'numeric', 'min:0' ],
                'weight_unit'     => [ 'nullable', 'string', 'in:g,kg,oz,lb' ],
                'length'          => [ 'nullable', 'numeric', 'min:0' ],
                'width'           => [ 'nullable', 'numeric', 'min:0' ],
                'height'          => [ 'nullable', 'numeric', 'min:0' ],
                'dim_unit'        => [ 'nullable', 'string', 'in:mm,cm,in' ],
                'position'        => [ 'integer', 'min:0' ],
                'meta'            => [ 'nullable', 'array' ],
                'option_values'   => [ 'array' ],
                'option_values.*' => [ 'integer' ],
            ],
            ProductRequest::priceRules( 'prices' ),
            ProductRequest::inventoryRules(),
        );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->sometimes( self::baseRules() );
    }
}
