<?php

/**
 * ProductChildrenRequest.
 *
 * Body of `POST admin/products/{product}/children`: the full, ordered list
 * of a grouped or bundled product's members.
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
class ProductChildrenRequest extends ApiFormRequest
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
            'children'              => [ 'present', 'array', 'max:100' ],
            'children.*'            => [ 'array' ],
            'children.*.product_id' => [ 'required', 'integer' ],
            'children.*.variant_id' => [ 'nullable', 'integer' ],
            'children.*.quantity'   => [ 'integer', 'min:1', 'max:1000' ],
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
