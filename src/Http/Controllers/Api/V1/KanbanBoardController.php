<?php

/**
 * KanbanBoardController.
 *
 * `kanban/boards` CRUD (engine spec §9.10, parent plan §9.5). The detail
 * response always includes the board's columns with their live card
 * counts, so a kanban UI can render WIP limits from one request.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\KanbanBoardRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\KanbanBoardResource;
use ArtisanPackUI\Ecommerce\Kanban\KanbanCardQuery;
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
class KanbanBoardController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    private const INCLUDES = [ 'columns' => 'columns.substatus', 'automations' => 'automations' ];

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List kanban boards', resource: KanbanBoardResource::class, collection: true, filters: [ 'is_active' => 'boolean', 'is_default' => 'boolean', 'key' => 'string' ], sorts: [ 'position', 'name' ], includes: self::INCLUDES )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            KanbanBoard::query(),
            $request,
            KanbanBoardResource::class,
            [ 'is_active' => [ 'is_active', 'bool' ], 'is_default' => [ 'is_default', 'bool' ], 'key' => 'key' ],
            [ 'position' => 'position', 'name' => 'name' ],
            self::INCLUDES,
            'position',
        );
    }

    /**
     * Board detail: columns (with card counts) are always included. With the
     * cards endpoint's `filter[...]` card filters, each column also carries
     * `filtered_card_count`; `card_count` stays the real count, which WIP
     * limits are checked against.
     *
     * @since 1.0.0
     * @since 1.2.0 Card filters add `filtered_card_count`.
     *
     * @param  Request      $request  Request.
     * @param  KanbanBoard  $board    Board.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Show a kanban board with its columns and WIP counts', resource: KanbanBoardResource::class, includes: self::INCLUDES, filters: [ 'search' => 'string', 'customer_id' => 'integer', 'tags' => 'string', 'tag_match' => 'string', 'product_ids' => 'string', 'placed_from' => 'string', 'placed_to' => 'string', 'moved_before' => 'string', 'stale' => 'boolean', 'payment_status' => 'string', 'fulfillment_status' => 'string' ] )]
    public function show( Request $request, KanbanBoard $board ): JsonResponse
    {
        $board->load( 'columns.substatus' );

        $filters = KanbanCardController::filters( $request );
        $counts  = KanbanCardQuery::for( $board )->filter( $filters )->countsByColumn( $board->columns );

        $board->columns->each( static function ( KanbanColumn $column ) use ( $counts, $filters ): void {
            $column->setAttribute( 'card_count', $counts[ $column->id ]['total'] ?? 0 );

            if ( ! $filters->isEmpty() ) {
                $column->setAttribute( 'filtered_card_count', $counts[ $column->id ]['count'] ?? 0 );
            }
        } );

        return $this->resourceResponse( $board, $request, KanbanBoardResource::class, self::INCLUDES );
    }

    /**
     * @since 1.0.0
     *
     * @param  KanbanBoardRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create a kanban board', resource: KanbanBoardResource::class, status: 201, includes: self::INCLUDES )]
    public function store( KanbanBoardRequest $request ): JsonResponse
    {
        return $this->resourceResponse( KanbanBoard::query()->create( $request->validated() ), $request, KanbanBoardResource::class, self::INCLUDES, 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  KanbanBoardRequest  $request  Validated request.
     * @param  KanbanBoard         $board    Board.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a kanban board', resource: KanbanBoardResource::class, includes: self::INCLUDES )]
    public function update( KanbanBoardRequest $request, KanbanBoard $board ): JsonResponse
    {
        $board->fill( $request->validated() )->save();

        return $this->resourceResponse( $board, $request, KanbanBoardResource::class, self::INCLUDES );
    }

    /**
     * Deletes the board; its columns, automations, and cards cascade.
     *
     * @since 1.0.0
     *
     * @param  Request      $request  Request.
     * @param  KanbanBoard  $board    Board.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a kanban board', resource: KanbanBoardResource::class )]
    public function destroy( Request $request, KanbanBoard $board ): JsonResponse
    {
        $board->delete();

        return $this->resourceResponse( $board, $request, KanbanBoardResource::class );
    }
}
