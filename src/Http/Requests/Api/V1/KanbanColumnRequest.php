<?php

/**
 * KanbanColumnRequest.
 *
 * Payload for `POST kanban/boards/{board}/columns` /
 * `PATCH kanban/columns/{column}`. The rules are
 * {@see KanbanColumnService::rules()}, shared with every in-process writer.
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
use ArtisanPackUI\Ecommerce\Services\KanbanColumnService;

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
     * @since 1.2.0 Delegates to {@see KanbanColumnService::rules()}; widget entries may carry config.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $column  = $this->route( 'column' );
        $board   = $this->route( 'board' );
        $boardId = $column instanceof KanbanColumn ? $column->board_id : ( $board instanceof KanbanBoard ? $board->id : null );

        return KanbanColumnService::rules( null === $boardId ? null : (int) $boardId, $column instanceof KanbanColumn ? $column : null, $this->all() );
    }

    /**
     * @since 1.2.0
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return parent::attributes() + KanbanColumnService::attributes( $this->all() );
    }
}
