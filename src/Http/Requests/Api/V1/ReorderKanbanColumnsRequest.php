<?php

/**
 * ReorderKanbanColumnsRequest.
 *
 * Body of `PUT kanban/boards/{board}/columns/order`: every column id on the
 * board, first to last (ArtisanPack-UI/ecommerce#203).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Requests\Api\V1;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
class ReorderKanbanColumnsRequest extends ApiFormRequest
{
    /**
     * @since 1.2.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'column_ids'   => [ 'required', 'array', 'list', 'min:1', 'max:500' ],
            'column_ids.*' => [ 'integer', 'distinct' ],
        ];
    }
}
