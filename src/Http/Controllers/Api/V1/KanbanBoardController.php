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
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
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
    #[ApiOperation( summary: 'List kanban boards', resource: KanbanBoardResource::class, collection: true )]
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
     * Board detail: columns (with card counts) are always included.
     *
     * @since 1.0.0
     *
     * @param  Request      $request  Request.
     * @param  KanbanBoard  $board    Board.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Show a kanban board with its columns and WIP counts', resource: KanbanBoardResource::class )]
    public function show( Request $request, KanbanBoard $board ): JsonResponse
    {
        $board->load( 'columns.substatus' );

        $counts = OrderBoardAssignment::query()
            ->active()
            ->where( 'board_id', $board->id )
            ->selectRaw( 'substatus_id, COUNT(*) as aggregate' )
            ->groupBy( 'substatus_id' )
            ->pluck( 'aggregate', 'substatus_id' );

        $board->columns->each( static function ( KanbanColumn $column ) use ( $counts ): void {
            $column->setAttribute( 'card_count', (int) ( $counts[ $column->substatus_id ] ?? 0 ) );
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
    #[ApiOperation( summary: 'Create a kanban board', resource: KanbanBoardResource::class, status: 201 )]
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
    #[ApiOperation( summary: 'Update a kanban board', resource: KanbanBoardResource::class )]
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
