<?php

/**
 * KanbanColumnController.
 *
 * Column CRUD: `POST kanban/boards/{board}/columns`,
 * `PATCH` / `DELETE kanban/columns/{column}` (engine spec §9.10). PATCH
 * covers reordering, relabelling, WIP limits, and the column's card
 * widgets.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1;

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\KanbanColumnRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\KanbanColumnResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanColumnController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  KanbanColumnRequest  $request  Validated request.
     * @param  KanbanBoard          $board    Board.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Add a column to a kanban board', resource: KanbanColumnResource::class, status: 201 )]
    public function store( KanbanColumnRequest $request, KanbanBoard $board ): JsonResponse
    {
        $column = $board->columns()->create( $request->validated() );

        return $this->resourceResponse( $column->load( 'substatus' ), $request, KanbanColumnResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  KanbanColumnRequest  $request  Validated request.
     * @param  KanbanColumn         $column   Column.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a kanban column', resource: KanbanColumnResource::class )]
    public function update( KanbanColumnRequest $request, KanbanColumn $column ): JsonResponse
    {
        $data = $request->validated();

        if ( array_key_exists( 'substatus_id', $data ) && (int) $data['substatus_id'] !== (int) $column->substatus_id && $column->cardCount() > 0 ) {
            return Problem::make( 422, 'column-not-empty', __( 'Column has cards' ), __( 'Move the cards out of this column before changing its sub-status.' ), $request );
        }

        $column->fill( $data )->save();

        return $this->resourceResponse( $column->load( 'substatus' ), $request, KanbanColumnResource::class );
    }

    /**
     * Deletes an empty column (and the automations that target it).
     *
     * @since 1.0.0
     *
     * @param  Request       $request  Request.
     * @param  KanbanColumn  $column   Column.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a kanban column', resource: KanbanColumnResource::class )]
    public function destroy( Request $request, KanbanColumn $column ): JsonResponse
    {
        if ( $column->cardCount() > 0 ) {
            return Problem::make( 422, 'column-not-empty', __( 'Column has cards' ), __( 'Move the cards out of this column before deleting it.' ), $request );
        }

        $column->load( 'substatus' )->delete();

        return $this->resourceResponse( $column, $request, KanbanColumnResource::class );
    }
}
