<?php

/**
 * KanbanRoutingService.
 *
 * Decides which kanban boards an order is on (parent plan §9.2). Multi-board
 * is the default: on placement the engine walks every active board in
 * `position` order and assigns the order to each board whose
 * `routing_rules` match —
 *
 * - a board with rules catches orders satisfying its condition tree
 *   ({@see OrderConditionEvaluator});
 * - a board with no rules catches every order;
 * - a board flagged `is_default` with no rules is the fallback, catching
 *   only orders no other board took.
 *
 * The final list runs through `ap.ecommerce.kanban.routingBoards`. Each
 * card lands in the board's entry column — the first column for the
 * order's current `system_status`, else the first column in the forward
 * chain. After an order edit, {@see self::reroute()} re-runs the rules:
 * newly-matching boards gain a card and rule-bearing boards that no longer
 * match lose theirs.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Events\KanbanBoardAssignmentAdded;
use ArtisanPackUI\Ecommerce\Events\KanbanBoardAssignmentRemoved;
use ArtisanPackUI\Ecommerce\Exceptions\EcommerceException;
use ArtisanPackUI\Ecommerce\Exceptions\KanbanOperationException;
use ArtisanPackUI\Ecommerce\Kanban\OrderConditionEvaluator;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanRoutingService
{
    /**
     * @since 1.0.0
     *
     * @param  OrderConditionEvaluator  $conditions  Evaluates routing rules.
     * @param  OrderStatusMachine       $statuses    Board / order status compatibility.
     */
    public function __construct(
        private readonly OrderConditionEvaluator $conditions,
        private readonly OrderStatusMachine $statuses,
    ) {
    }

    /**
     * Ids of the active boards `$order` should be on, in board order.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return array<int, int>
     */
    public function matchingBoardIds( Order $order ): array
    {
        $boards   = $this->activeBoards();
        $matched  = [];
        $fallback = [];

        foreach ( $boards as $board ) {
            if ( ! $board->hasRoutingRules() && $board->is_default ) {
                $fallback[] = (int) $board->id;

                continue;
            }

            if ( ! $board->hasRoutingRules() ) {
                $matched[] = (int) $board->id;

                continue;
            }

            if ( $this->conditions->passes( (array) $board->routing_rules, $order ) ) {
                $matched[] = (int) $board->id;
            }
        }

        if ( [] === $matched ) {
            $matched = $fallback;
        }

        $filtered = (array) applyFilters( 'ap.ecommerce.kanban.routingBoards', $matched, $order );
        $active   = $boards->modelKeys();

        return array_values( array_unique( array_filter(
            array_map( 'intval', array_filter( $filtered, 'is_numeric' ) ),
            static fn ( int $id ): bool => in_array( $id, $active, true ),
        ) ) );
    }

    /**
     * Places a newly-placed order on every matching board. A board that
     * can't take the order (no usable entry column) is logged and skipped
     * so routing never blocks placement.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Placed order.
     *
     * @return array<int, OrderBoardAssignment> The order's assignment on each matched board.
     */
    public function route( Order $order ): array
    {
        $added = [];

        foreach ( $this->matchingBoardIds( $order ) as $boardId ) {
            $assignment = $this->tryAssign( $order, $boardId );

            if ( null !== $assignment ) {
                $added[] = $assignment;
            }
        }

        return $added;
    }

    /**
     * Re-runs routing after an order edit: boards that now match gain the
     * card; boards with routing rules that no longer match lose it. Boards
     * without rules (catch-all or fallback) keep their cards. Fires
     * `ap.ecommerce.kanban.boardReassigned` when anything changed.
     *
     * @since 1.0.0
     *
     * @param  Order     $order        Edited order.
     * @param  int|null  $actorUserId  Acting user, if any.
     *
     * @return array{added: array<int, int>, removed: array<int, int>} Board ids.
     */
    public function reroute( Order $order, ?int $actorUserId = null ): array
    {
        $target  = $this->matchingBoardIds( $order );
        $current = OrderBoardAssignment::query()
            ->active()
            ->where( 'order_id', $order->id )
            ->with( 'board' )
            ->get();

        $currentIds = array_map( 'intval', $current->pluck( 'board_id' )->all() );
        $added      = [];
        $removed    = [];

        foreach ( array_diff( $target, $currentIds ) as $boardId ) {
            if ( null !== $this->tryAssign( $order, $boardId, $actorUserId ) ) {
                $added[] = $boardId;
            }
        }

        foreach ( $current as $assignment ) {
            if ( in_array( (int) $assignment->board_id, $target, true ) || ! $assignment->board->hasRoutingRules() ) {
                continue;
            }

            $this->remove( $order, $assignment->board, $actorUserId );
            $removed[] = (int) $assignment->board_id;
        }

        if ( [] !== $added || [] !== $removed ) {
            AfterCommit::action( 'ap.ecommerce.kanban.boardReassigned', $order, $added, $removed );
        }

        return [ 'added' => $added, 'removed' => $removed ];
    }

    /**
     * Puts `$order` on `$board`, in `$column` or the board's entry column.
     * Re-activates a previously removed assignment; an order already on the
     * board is returned unchanged.
     *
     * @since 1.0.0
     *
     * @param  Order              $order        Order.
     * @param  KanbanBoard        $board        Board.
     * @param  KanbanColumn|null  $column       Column to start in; defaults to the entry column.
     * @param  int|null           $actorUserId  Acting user, if any.
     *
     * @throws KanbanOperationException When the column isn't on the board or the board has no usable column.
     * @throws \ArtisanPackUI\Ecommerce\Exceptions\IncompatibleBoardSubstatusException When the column's sub-status can't hold this order.
     *
     * @return OrderBoardAssignment
     */
    public function assign( Order $order, KanbanBoard $board, ?KanbanColumn $column = null, ?int $actorUserId = null ): OrderBoardAssignment
    {
        [ $assignment, $changed ] = DB::transaction( function () use ( $order, $board, $column, $actorUserId ): array {
            $locked   = Order::query()->lockForUpdate()->findOrFail( $order->id );
            $existing = OrderBoardAssignment::query()
                ->where( 'order_id', $locked->id )
                ->where( 'board_id', $board->id )
                ->lockForUpdate()
                ->first();

            if ( null !== $existing && $existing->isActive() ) {
                return [ $existing, false ];
            }

            $column ??= $this->entryColumn( $board, $locked );

            if ( null === $column ) {
                throw new KanbanOperationException( 'no-entry-column', __( 'Board ":board" has no column that can hold an order that is :status.', [
                    'board'  => $board->name,
                    'status' => $locked->system_status,
                ] ) );
            }

            if ( (int) $column->board_id !== (int) $board->id ) {
                throw new KanbanOperationException( 'column-not-on-board', __( 'Column :column is not on board ":board".', [ 'column' => $column->id, 'board' => $board->name ] ) );
            }

            $this->statuses->assertBoardAssignmentCompatible( $locked, $column->substatus );

            $now        = Carbon::now();
            $attributes = [ 'substatus_id' => $column->substatus_id, 'assigned_at' => $now, 'moved_at' => $now, 'removed_at' => null ];
            $assignment = $existing ?? new OrderBoardAssignment( [ 'order_id' => $locked->id, 'board_id' => $board->id ] );
            $assignment->fill( $attributes )->save();

            OrderTimelineEntry::query()->create( [
                'order_id'      => $locked->id,
                'actor_user_id' => $actorUserId,
                'event_type'    => 'kanban.assignment_added',
                'payload'       => [ 'board_id' => $board->id, 'board_key' => $board->key, 'substatus_id' => $column->substatus_id ],
            ] );

            return [ $assignment, true ];
        } );

        if ( $changed ) {
            AfterCommit::action( 'ap.ecommerce.kanban.boardAssignmentAdded', $assignment );
            Event::dispatch( new KanbanBoardAssignmentAdded( $assignment ) );
        }

        return $assignment;
    }

    /**
     * Takes `$order` off `$board` by stamping `removed_at`.
     *
     * @since 1.0.0
     *
     * @param  Order        $order        Order.
     * @param  KanbanBoard  $board        Board.
     * @param  int|null     $actorUserId  Acting user, if any.
     *
     * @throws KanbanOperationException When the order is not on the board.
     *
     * @return OrderBoardAssignment
     */
    public function remove( Order $order, KanbanBoard $board, ?int $actorUserId = null ): OrderBoardAssignment
    {
        $assignment = DB::transaction( function () use ( $order, $board, $actorUserId ): OrderBoardAssignment {
            $assignment = OrderBoardAssignment::query()
                ->active()
                ->where( 'order_id', $order->id )
                ->where( 'board_id', $board->id )
                ->lockForUpdate()
                ->first();

            if ( null === $assignment ) {
                throw new KanbanOperationException( 'not-on-board', __( 'Order :order is not on board ":board".', [ 'order' => $order->id, 'board' => $board->name ] ) );
            }

            $assignment->removed_at = Carbon::now();
            $assignment->save();

            OrderTimelineEntry::query()->create( [
                'order_id'      => $order->id,
                'actor_user_id' => $actorUserId,
                'event_type'    => 'kanban.assignment_removed',
                'payload'       => [ 'board_id' => $board->id, 'board_key' => $board->key, 'substatus_id' => $assignment->substatus_id ],
            ] );

            return $assignment;
        } );

        AfterCommit::action( 'ap.ecommerce.kanban.boardAssignmentRemoved', $assignment );
        Event::dispatch( new KanbanBoardAssignmentRemoved( $assignment ) );

        return $assignment;
    }

    /**
     * The column a new card on `$board` starts in: the first column whose
     * sub-status matches the order's `system_status`, else the first column
     * the order may occupy (see
     * {@see OrderStatusMachine::isBoardAssignmentCompatible()}).
     *
     * @since 1.0.0
     *
     * @param  KanbanBoard  $board  Board.
     * @param  Order        $order  Order.
     *
     * @return KanbanColumn|null
     */
    public function entryColumn( KanbanBoard $board, Order $order ): ?KanbanColumn
    {
        /** @var Collection<int, KanbanColumn> $columns */
        $columns = $board->columns()->with( 'substatus' )->get();

        return $columns->first( static fn ( KanbanColumn $column ): bool => $column->substatus->system_status === $order->system_status )
            ?? $columns->first( fn ( KanbanColumn $column ): bool => $this->statuses->isBoardAssignmentCompatible( $order, $column->substatus ) );
    }

    /**
     * Active boards in routing order.
     *
     * @since 1.0.0
     *
     * @return Collection<int, KanbanBoard>
     */
    protected function activeBoards(): Collection
    {
        return KanbanBoard::query()
            ->where( 'is_active', true )
            ->orderBy( 'position' )
            ->orderBy( 'id' )
            ->get();
    }

    /**
     * {@see self::assign()} for routing: failures are logged, not thrown.
     *
     * @since 1.0.0
     *
     * @param  Order     $order        Order.
     * @param  int       $boardId      Board id.
     * @param  int|null  $actorUserId  Acting user, if any.
     *
     * @return OrderBoardAssignment|null
     */
    private function tryAssign( Order $order, int $boardId, ?int $actorUserId = null ): ?OrderBoardAssignment
    {
        $board = KanbanBoard::query()->find( $boardId );

        if ( null === $board ) {
            return null;
        }

        try {
            return $this->assign( $order, $board, null, $actorUserId );
        } catch ( EcommerceException $e ) {
            Log::channel( 'ecommerce' )->warning( 'Kanban routing could not place the order on a board.', [
                'order_id' => $order->id,
                'board_id' => $boardId,
                'error'    => $e->getMessage(),
            ] );

            return null;
        }
    }
}
