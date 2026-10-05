<?php

/**
 * UpdateInventoryItemRequest.
 *
 * Body of `PATCH admin/inventory/{item}` (engine issue #140): the row's
 * stock settings. Quantities change through `POST …/adjust`.
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
class UpdateInventoryItemRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->sometimes( [
            'track_inventory'     => [ 'boolean' ],
            'allow_backorder'     => [ 'boolean' ],
            'low_stock_threshold' => [ 'nullable', 'integer', 'min:0', 'max:1000000' ],
        ] );
    }
}
