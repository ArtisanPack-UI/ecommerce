<?php

/**
 * ProductImageRequest.
 *
 * Body of `POST admin/products/{product}/images` and
 * `PATCH admin/products/{product}/images/{image}`.
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
class ProductImageRequest extends ApiFormRequest
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
            'media_id'  => [ 'nullable', 'integer', 'min:1' ],
            'image_url' => [ 'nullable', 'string', 'max:1000' ],
            'alt_text'  => [ 'nullable', 'string', 'max:255' ],
            'position'  => [ 'integer', 'min:0' ],
        ];
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
