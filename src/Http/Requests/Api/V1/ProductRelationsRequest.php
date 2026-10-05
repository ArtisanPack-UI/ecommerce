<?php

/**
 * ProductRelationsRequest.
 *
 * `POST admin/products/{product}/relations` (#182): the relation type and
 * the related product ids, in order.
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

use ArtisanPackUI\Ecommerce\Models\ProductRelation;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductRelationsRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type'  => [ 'required', 'string', Rule::in( ProductRelation::TYPES ) ],
            'ids'   => [ 'present', 'array', 'max:50' ],
            'ids.*' => [ 'integer', 'min:1' ],
        ];
    }
}
