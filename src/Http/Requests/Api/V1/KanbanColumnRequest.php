<?php

/**
 * KanbanColumnRequest.
 *
 * Payload for `POST kanban/boards/{board}/columns` /
 * `PATCH kanban/columns/{column}`. A board has at most one column per
 * sub-status; `card_widgets` must name registered card widgets.
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
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanColumnRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $column  = $this->route( 'column' );
        $board   = $this->route( 'board' );
        $boardId = $column instanceof KanbanColumn ? $column->board_id : ( $board instanceof KanbanBoard ? $board->id : null );

        return $this->sometimes( [
            'substatus_id'   => [
                'integer',
                Rule::exists( 'order_substatuses', 'id' ),
                Rule::unique( 'kanban_columns', 'substatus_id' )
                    ->where( 'board_id', $boardId )
                    ->ignore( $column instanceof KanbanColumn ? $column->id : null ),
            ],
            'label_override' => [ 'nullable', 'string', 'max:120' ],
            'color_override' => [ 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/' ],
            'icon_override'  => [ 'nullable', 'string', 'max:80' ],
            'position'       => [ 'integer', 'min:0' ],
            'wip_limit'      => [ 'nullable', 'integer', 'min:1' ],
            'card_widgets'   => [ 'array' ],
            'card_widgets.*' => [ 'string', Rule::in( app( KanbanCardWidgetRegistry::class )->keys() ) ],
        ], [ 'substatus_id' ] );
    }
}
