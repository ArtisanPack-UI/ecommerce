<?php

/**
 * KanbanAutomationRequest.
 *
 * Payload for `POST kanban/boards/{board}/automations` /
 * `PATCH kanban/automations/{automation}`. Both columns must be on the
 * automation's board, `trigger_key` must name a registered trigger, and
 * `conditions` must be a well-formed condition tree.
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

use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanAutomationRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $automation = $this->route( 'automation' );
        $board      = $this->route( 'board' );
        $boardId    = $automation instanceof KanbanAutomation ? $automation->board_id : ( $board instanceof KanbanBoard ? $board->id : null );
        $onBoard    = Rule::exists( 'kanban_columns', 'id' )->where( 'board_id', $boardId );

        return $this->sometimes( [
            'from_column_id' => [ 'nullable', 'integer', $onBoard ],
            'to_column_id'   => [ 'integer', $onBoard ],
            'trigger_key'    => [ 'string', Rule::in( app( KanbanAutomationRegistry::class )->keys() ) ],
            'trigger_config' => [ 'array' ],
            'conditions'     => [ 'array', KanbanBoardRequest::conditionTree() ],
            'is_active'      => [ 'boolean' ],
        ], [ 'to_column_id', 'trigger_key' ] );
    }
}
