<?php

/**
 * KanbanAssignmentController.
 *
 * Manual board assignments (engine spec §9.10, parent plan §9.2):
 * `POST` / `DELETE kanban/boards/{board}/assignments/{order}` put an order
 * on a board or take it off, firing
 * `ap.ecommerce.kanban.boardAssignmentAdded` / `…Removed`.
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

use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\Concerns\RendersKanbanProblems;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\AssignKanbanBoardRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\KanbanCardResource;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\KanbanRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanAssignmentController extends ApiController
{
    use RendersKanbanProblems;

    /**
     * @since 1.0.0
     *
     * @param  KanbanRoutingService  $routing  Board assignments.
     */
    public function __construct( private readonly KanbanRoutingService $routing )
    {
    }

    /**
     * Puts `$order` on `$board` (in `column_id`, or the entry column).
     *
     * @since 1.0.0
     *
     * @param  AssignKanbanBoardRequest  $request  Validated request.
     * @param  KanbanBoard               $board    Board.
     * @param  Order                     $order    Order.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Put an order on a kanban board', resource: KanbanCardResource::class )]
    public function store( AssignKanbanBoardRequest $request, KanbanBoard $board, Order $order ): JsonResponse
    {
        return $this->kanbanOperation( $request, function () use ( $request, $board, $order ): JsonResponse {
            $columnId = $request->validated( 'column_id' );
            $column   = null === $columnId ? null : KanbanColumn::query()->with( 'substatus' )->findOrFail( (int) $columnId );
            $card     = $this->routing->assign( $order, $board, $column, $this->actorId( $request ) );

            return $this->resourceResponse( $card->load( 'order' ), $request, KanbanCardResource::class );
        } );
    }

    /**
     * Takes `$order` off `$board`.
     *
     * @since 1.0.0
     *
     * @param  Request      $request  Request.
     * @param  KanbanBoard  $board    Board.
     * @param  Order        $order    Order.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Take an order off a kanban board', resource: KanbanCardResource::class )]
    public function destroy( Request $request, KanbanBoard $board, Order $order ): JsonResponse
    {
        return $this->kanbanOperation( $request, function () use ( $request, $board, $order ): JsonResponse {
            $card = $this->routing->remove( $order, $board, $this->actorId( $request ) );

            return $this->resourceResponse( $card->load( 'order' ), $request, KanbanCardResource::class );
        } );
    }
}
