<?php

/**
 * AssignKanbanBoardRequest.
 *
 * Payload for `POST kanban/boards/{board}/assignments/{order}`. Without a
 * `column_id` the card starts in the board's entry column.
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

use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class AssignKanbanBoardRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $board = $this->route( 'board' );

        return [
            'column_id' => [ 'nullable', 'integer', Rule::exists( 'kanban_columns', 'id' )->where( 'board_id', $board instanceof KanbanBoard ? $board->id : null ) ],
        ];
    }
}
