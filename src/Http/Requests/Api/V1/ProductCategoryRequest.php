<?php

/**
 * ProductCategoryRequest.
 *
 * Body of `POST admin/product-categories` and
 * `PATCH admin/product-categories/{category}` (engine spec §9.5).
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
class ProductCategoryRequest extends ApiFormRequest
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
            'parent_id'      => [ 'nullable', 'integer' ],
            'name'           => [ 'string', 'max:255' ],
            'slug'           => [ 'nullable', 'string', 'max:255' ],
            'description'    => [ 'nullable', 'string' ],
            'image_media_id' => [ 'nullable', 'integer', 'min:1' ],
            'icon'           => [ 'nullable', 'string', 'max:80' ],
            'position'       => [ 'integer', 'min:0' ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->sometimes( self::baseRules(), [ 'name' ] );
    }
}
