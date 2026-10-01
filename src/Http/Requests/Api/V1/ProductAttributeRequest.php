<?php

/**
 * ProductAttributeRequest.
 *
 * Body of `POST admin/products/{product}/attributes` and
 * `PATCH admin/products/{product}/attributes/{attribute}`.
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
class ProductAttributeRequest extends ApiFormRequest
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
        return array_merge( [
            'key'          => [ 'nullable', 'string', 'max:60' ],
            'label'        => [ 'string', 'max:120' ],
            'position'     => [ 'integer', 'min:0' ],
            'is_variation' => [ 'boolean' ],
        ], self::valueRules( '' ) );
    }

    /**
     * Rules for an attribute's `values` list, with a key prefix.
     *
     * @since 1.0.0
     *
     * @param  string  $prefix  Key prefix (e.g. `attributes.*.`).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function valueRules( string $prefix ): array
    {
        return [
            "{$prefix}values"          => [ 'array' ],
            "{$prefix}values.*"        => [ 'array' ],
            "{$prefix}values.*.id"     => [ 'nullable', 'integer' ],
            "{$prefix}values.*.value"  => [ 'nullable', 'string', 'max:120' ],
            "{$prefix}values.*.label"  => [ 'required', 'string', 'max:120' ],
            "{$prefix}values.*.swatch" => [ 'nullable', 'string', 'max:60' ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->sometimes( self::baseRules(), [ 'label' ] );
    }
}
