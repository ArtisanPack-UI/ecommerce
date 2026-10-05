<?php

/**
 * MergeProductTagRequest.
 *
 * Body of `POST admin/product-tags/{tag}/merge`: the tag to keep.
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

use ArtisanPackUI\Ecommerce\Models\ProductTag;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class MergeProductTagRequest extends ApiFormRequest
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
            'target_id' => [ 'required', 'integer', Rule::exists( ProductTag::class, 'id' ) ],
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
