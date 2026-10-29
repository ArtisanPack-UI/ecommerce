<?php

/**
 * KanbanColumnController.
 *
 * Column CRUD: `POST kanban/boards/{board}/columns`,
 * `PATCH` / `DELETE kanban/columns/{column}` (engine spec §9.10), and
 * `PUT kanban/boards/{board}/columns/order` for a bulk reorder. PATCH
 * covers relabelling, WIP limits, and the column's card widgets. Every
 * write goes through {@see KanbanColumnService}.
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

use ArtisanPackUI\Ecommerce\Auth\EcommerceAuthorizer;
use ArtisanPackUI\Ecommerce\Exceptions\KanbanColumnNotEmptyException;
use ArtisanPackUI\Ecommerce\Exceptions\KanbanOperationException;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\Concerns\RendersKanbanProblems;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\KanbanColumnRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ReorderKanbanColumnsRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\KanbanColumnResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\KanbanColumnService;
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
    use RendersKanbanProblems;

    /**
     * @since 1.2.0
     *
     * @param  KanbanColumnService  $columns  Column writes.
     */
    public function __construct( private readonly KanbanColumnService $columns )
    {
    }

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
        $column = $this->columns->create( $board, $request->validated(), $this->actorId( $request ) );

        return $this->resourceResponse( $column, $request, KanbanColumnResource::class, [], 201 );
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
        try {
            $column = $this->columns->update( $column, $request->validated(), $this->actorId( $request ) );
        } catch ( KanbanOperationException $e ) {
            return Problem::make( 422, $e->errorCode, __( 'Column has cards' ), $e->getMessage(), $request );
        }

        return $this->resourceResponse( $column, $request, KanbanColumnResource::class );
    }

    /**
     * Deletes an empty column (and the automations that target it). With
     * `move_cards_to` (a column id on the same board) its cards are moved
     * there first, which also needs `kanbanCard.move`.
     *
     * @since 1.0.0
     * @since 1.2.0 Accepts `move_cards_to`.
     *
     * @param  Request       $request  Request.
     * @param  KanbanColumn  $column   Column.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a kanban column', resource: KanbanColumnResource::class )]
    public function destroy( Request $request, KanbanColumn $column ): JsonResponse
    {
        $target = $request->input( 'move_cards_to' );
        $moveTo = is_numeric( $target ) ? KanbanColumn::query()->find( (int) $target ) : null;

        if ( null !== $target && null === $moveTo ) {
            return Problem::make( 422, 'column-not-on-board', __( 'Kanban operation refused' ), __( 'The column to move the cards to was not found.' ), $request );
        }

        if ( null !== $moveTo && ! app( EcommerceAuthorizer::class )->allows( $request->user(), 'kanbanCard', 'move', $column->board ) ) {
            return Problem::make( 403, 'forbidden', __( 'Forbidden' ), __( 'Missing ability :ability.', [ 'ability' => 'ecommerce.kanbanCard.move' ] ), $request );
        }

        return $this->kanbanOperation( $request, function () use ( $request, $column, $moveTo ): JsonResponse {
            try {
                $deleted = $this->columns->delete( $column, $moveTo, $this->actorId( $request ) );
            } catch ( KanbanColumnNotEmptyException $e ) {
                return Problem::make( 422, 'column-not-empty', __( 'Column has cards' ), $e->getMessage(), $request );
            }

            return $this->resourceResponse( $deleted, $request, KanbanColumnResource::class );
        } );
    }

    /**
     * Puts the board's columns in the order of `column_ids` in one write.
     *
     * @since 1.2.0
     *
     * @param  ReorderKanbanColumnsRequest  $request  Validated request.
     * @param  KanbanBoard                  $board    Board.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Reorder a kanban board\'s columns', resource: KanbanColumnResource::class, collection: true )]
    public function reorder( ReorderKanbanColumnsRequest $request, KanbanBoard $board ): JsonResponse
    {
        return $this->kanbanOperation( $request, function () use ( $request, $board ): JsonResponse {
            $columns = $this->columns->reorder( $board, (array) $request->validated( 'column_ids' ), $this->actorId( $request ) );

            return new JsonResponse( [ 'data' => KanbanColumnResource::collection( $columns )->resolve( $request ) ] );
        } );
    }
}
