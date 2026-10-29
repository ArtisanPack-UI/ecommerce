<?php

/**
 * KanbanCardController.
 *
 * Cards on a board (engine spec §9.10, parent plan §9.5):
 *
 * - `GET kanban/boards/{board}/cards` — cursor-paginated cards with their
 *   rendered widget payloads; `filter[column_id]` narrows to one column.
 * - `POST kanban/cards/{order}/move` — `{ to_column_id }` moves the order's
 *   card on that column's board, rolls the order status up, runs the
 *   board's automations, and broadcasts the move. `position`,
 *   `before_order_id`, or `after_order_id` place the card in the column;
 *   with one, a move into the card's own column reorders it.
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
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\MoveKanbanCardRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\KanbanCardResource;
use ArtisanPackUI\Ecommerce\Http\Support\ListQuery;
use ArtisanPackUI\Ecommerce\Kanban\KanbanCardFilters;
use ArtisanPackUI\Ecommerce\Kanban\KanbanCardQuery;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\KanbanBoardService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanCardController extends ApiController
{
    use RendersKanbanProblems;

    /**
     * @since 1.0.0
     *
     * @param  KanbanBoardService  $boards  Card moves.
     */
    public function __construct( private readonly KanbanBoardService $boards )
    {
    }

    /**
     * Cards on `$board`, in their manual order by default (position, then
     * when they joined the board); `sort=moved_at` / `sort=assigned_at`
     * order by column entry / board entry instead.
     *
     * `filter[column_id]` narrows to columns; `filter[search]`,
     * `filter[customer_id]`, `filter[tags]` (+ `filter[tag_match]=all`),
     * `filter[product_ids]`, `filter[placed_from]`, `filter[placed_to]`,
     * `filter[moved_before]`, `filter[stale]`, `filter[payment_status]`, and
     * `filter[fulfillment_status]` narrow the cards through
     * {@see KanbanCardQuery} (lists are comma-separated).
     *
     * @since 1.0.0
     * @since 1.2.0 Manual order by default; the {@see KanbanCardFilters} filters.
     *
     * @param  Request      $request  Request.
     * @param  KanbanBoard  $board    Board.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List the cards on a kanban board', resource: KanbanCardResource::class, collection: true, filters: [ 'column_id' => 'string', 'search' => 'string', 'customer_id' => 'integer', 'tags' => 'string', 'tag_match' => 'string', 'product_ids' => 'string', 'placed_from' => 'string', 'placed_to' => 'string', 'moved_before' => 'string', 'stale' => 'boolean', 'payment_status' => 'string', 'fulfillment_status' => 'string' ], sorts: [ 'position', 'moved_at', 'assigned_at' ] )]
    public function index( Request $request, KanbanBoard $board ): JsonResponse
    {
        $columns = $board->columns()->with( 'board' )->get()->keyBy( 'substatus_id' );
        $query   = KanbanCardQuery::for( $board )->filter( self::filters( $request ) )->builder();

        // KanbanCardQuery applies the card filters; ListQuery only validates them.
        $handled = array_fill_keys( KanbanCardFilters::KEYS, static function (): void {
        } );

        ListQuery::apply(
            $query,
            $request,
            [
                'column_id' => static function ( Builder $query, string $value ) use ( $board ): void {
                    $query->whereIn( 'substatus_id', KanbanColumn::query()
                        ->where( 'board_id', $board->id )
                        ->whereIn( 'id', array_map( 'intval', array_filter( explode( ',', $value ), 'is_numeric' ) ) )
                        ->select( 'substatus_id' ) );
                },
            ] + $handled,
            [ 'position' => 'position', 'moved_at' => 'moved_at', 'assigned_at' => 'assigned_at' ],
            'position,assigned_at',
        );

        $paginator = ListQuery::paginate( $query, $request );

        foreach ( $paginator->items() as $card ) {
            $card->setRelation( 'column', $columns->get( $card->substatus_id ) );
            $card->order?->setRelation( 'boardAssignments', new EloquentCollection( [ $card ] ) );
        }

        $payload         = KanbanCardResource::collection( $paginator )->response( $request )->getData( true );
        $payload['data'] = (array) applyFilters( 'ap.ecommerce.api.list.' . KanbanCardResource::NAME, $payload['data'], $query, $request );

        return new JsonResponse( $payload );
    }

    /**
     * The {@see KanbanCardFilters} in a request's `filter[...]` parameters.
     *
     * @since 1.2.0
     *
     * @param  Request  $request  Request.
     *
     * @return KanbanCardFilters
     */
    public static function filters( Request $request ): KanbanCardFilters
    {
        $values = $request->query( 'filter', [] );

        return KanbanCardFilters::fromArray( array_intersect_key( is_array( $values ) ? $values : [], array_flip( KanbanCardFilters::KEYS ) ) );
    }

    /**
     * Moves `$order`'s card into `to_column_id`.
     *
     * @since 1.0.0
     *
     * @param  MoveKanbanCardRequest  $request  Validated request.
     * @param  Order                  $order    Order.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Move a card to another column', resource: KanbanCardResource::class, description: 'Moves the order\'s card on the target column\'s board. The order\'s system status rolls up from all of its boards, the board\'s automations run, and the move is broadcast on private-ecommerce.kanban.board.{board}.' )]
    public function move( MoveKanbanCardRequest $request, Order $order ): JsonResponse
    {
        return $this->kanbanOperation( $request, function () use ( $request, $order ): JsonResponse {
            $column = KanbanColumn::query()->with( 'board' )->findOrFail( (int) $request->validated( 'to_column_id' ) );
            $card   = $this->boards->move( $order, $column, $this->actorId( $request ), $request->validated( 'reason' ), $request->placement() );

            return $this->resourceResponse( $card->load( 'order' ), $request, KanbanCardResource::class );
        } );
    }
}
