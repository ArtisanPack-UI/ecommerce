<?php

/**
 * KanbanBoardService.
 *
 * Moves cards between columns (parent plan §9.4). A move — whether from a
 * drag in a kanban UI, an external event, or a rule — goes through
 * {@see self::move()}, which:
 *
 * 1. runs the `ap.ecommerce.kanban.cardMoving` filter (return `null` to
 *    veto) and enforces the target column's WIP limit;
 * 2. rolls the order's `system_status` up from all of its boards
 *    ({@see OrderStatusMachine::rollUpBoardStatus()}) and transitions the
 *    order when that changes — moving a card into an exit column
 *    (cancelled, refunded, failed) moves the whole order there;
 * 3. updates the assignment's sub-status and fires
 *    `ap.ecommerce.order.substatusChanged` / `OrderSubstatusChanged`;
 * 4. after commit, fires `ap.ecommerce.kanban.cardMoved` and dispatches
 *    {@see KanbanCardMoved}, which runs the board's automations and
 *    broadcasts the move.
 *
 * Only the moved board's assignment changes; the order's other cards stay
 * where they are.
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

use ArtisanPackUI\Ecommerce\Events\KanbanCardMoved;
use ArtisanPackUI\Ecommerce\Exceptions\KanbanOperationException;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanBoardService
{
    /**
     * @since 1.0.0
     *
     * @param  OrderStatusMachine  $statuses  Order status transitions + board roll-up.
     */
    public function __construct( private readonly OrderStatusMachine $statuses )
    {
    }

    /**
     * Moves `$order`'s card on `$to`'s board into `$to`.
     *
     * @since 1.0.0
     *
     * @param  Order         $order        Order whose card moves.
     * @param  KanbanColumn  $to           Target column (its board is the board the card moves on).
     * @param  int|null      $actorUserId  Acting user; `null` for system moves.
     * @param  string|null   $reason       Free-text reason for the timeline.
     *
     * @throws KanbanOperationException When the order isn't on the board, the move is vetoed, or the column is full.
     * @throws \ArtisanPackUI\Ecommerce\Exceptions\InvalidOrderStatusTransitionException When the roll-up would make an illegal status move.
     * @throws \ArtisanPackUI\Ecommerce\Exceptions\IncompatibleBoardSubstatusException When the column can't hold the order at its status.
     *
     * @return OrderBoardAssignment The updated assignment.
     */
    public function move( Order $order, KanbanColumn $to, ?int $actorUserId = null, ?string $reason = null ): OrderBoardAssignment
    {
        $result = DB::transaction( function () use ( $order, $to, $actorUserId, $reason ): ?array {
            // Lock the order before its assignment — the same order
            // KanbanRoutingService::assign() uses — so the two can't deadlock.
            $locked     = Order::query()->lockForUpdate()->findOrFail( $order->id );
            $board      = KanbanBoard::query()->findOrFail( $to->board_id );
            $assignment = OrderBoardAssignment::query()
                ->active()
                ->where( 'order_id', $order->id )
                ->where( 'board_id', $board->id )
                ->lockForUpdate()
                ->first();

            if ( null === $assignment ) {
                throw new KanbanOperationException( 'not-on-board', __( 'Order :order is not on board ":board".', [ 'order' => $order->id, 'board' => $board->name ] ) );
            }

            $from = $assignment->currentColumn()
                ?? new KanbanColumn( [ 'board_id' => $board->id, 'substatus_id' => $assignment->substatus_id ] );

            $to = $this->filteredTarget( $assignment, $from, $to, $board, $actorUserId, $reason );

            if ( (int) $to->substatus_id === (int) $assignment->substatus_id ) {
                return null;
            }

            if ( null !== $to->wip_limit && $to->cardCount() >= $to->wip_limit ) {
                throw new KanbanOperationException( 'wip-limit-reached', __( 'Column ":column" is at its limit of :limit cards.', [
                    'column' => $to->displayLabel(),
                    'limit'  => $to->wip_limit,
                ] ) );
            }

            /** @var OrderSubstatus $target */
            $target = $to->substatus()->firstOrFail();
            $locked = $this->rollUp( $locked, $assignment, $target, $actorUserId, $reason );

            $assignment->substatus_id = $target->id;
            $assignment->moved_at     = Carbon::now();
            $assignment->save();

            $this->statuses->setSubstatus( $locked, $target, $actorUserId, $reason, (int) $board->id );

            return [ $assignment, $from, $to, $board ];
        } );

        if ( null === $result ) {
            return OrderBoardAssignment::query()->where( 'order_id', $order->id )->where( 'board_id', $to->board_id )->firstOrFail();
        }

        [ $assignment, $from, $to, $board ]  = $result;
        $refreshed                           = $order->fresh() ?? $order;

        doAction( 'ap.ecommerce.kanban.cardMoved', $refreshed, $from, $to, $board );
        Event::dispatch( new KanbanCardMoved( $refreshed, $from, $to, $board ) );

        return $assignment;
    }

    /**
     * Runs the `ap.ecommerce.kanban.cardMoving` filter and returns the
     * (possibly redirected) target column.
     *
     * @since 1.0.0
     *
     * @param  OrderBoardAssignment  $assignment   Card being moved.
     * @param  KanbanColumn          $from         Current column.
     * @param  KanbanColumn          $to           Requested column.
     * @param  KanbanBoard           $board        Board.
     * @param  int|null              $actorUserId  Acting user.
     * @param  string|null           $reason       Reason.
     *
     * @throws KanbanOperationException When the filter vetoes the move or redirects it off the board.
     *
     * @return KanbanColumn
     */
    protected function filteredTarget(
        OrderBoardAssignment $assignment,
        KanbanColumn $from,
        KanbanColumn $to,
        KanbanBoard $board,
        ?int $actorUserId,
        ?string $reason,
    ): KanbanColumn {
        $move = applyFilters( 'ap.ecommerce.kanban.cardMoving', [
            'order_id'       => (int) $assignment->order_id,
            'board_id'       => (int) $board->id,
            'from_column_id' => $from->exists ? (int) $from->id : null,
            'to_column_id'   => (int) $to->id,
            'actor_user_id'  => $actorUserId,
            'reason'         => $reason,
        ], $assignment );

        if ( ! is_array( $move ) || ! is_numeric( $move['to_column_id'] ?? null ) ) {
            throw new KanbanOperationException( 'move-rejected', __( 'The card move was rejected.' ) );
        }

        if ( (int) $move['to_column_id'] === (int) $to->id ) {
            return $to;
        }

        $redirected = KanbanColumn::query()->where( 'board_id', $board->id )->find( (int) $move['to_column_id'] );

        if ( null === $redirected ) {
            throw new KanbanOperationException( 'column-not-on-board', __( 'Column :column is not on board ":board".', [ 'column' => $move['to_column_id'], 'board' => $board->name ] ) );
        }

        return $redirected;
    }

    /**
     * Transitions the order when moving `$assignment` onto `$target` changes
     * the status its boards roll up to, and keeps the order's global default
     * sub-status on the new status.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order        Locked order.
     * @param  OrderBoardAssignment  $assignment   Card being moved.
     * @param  OrderSubstatus        $target       Sub-status the card moves onto.
     * @param  int|null              $actorUserId  Acting user.
     * @param  string|null           $reason       Reason.
     *
     * @return Order The order after any transition.
     */
    protected function rollUp( Order $order, OrderBoardAssignment $assignment, OrderSubstatus $target, ?int $actorUserId, ?string $reason ): Order
    {
        $siblings = OrderBoardAssignment::query()
            ->active()
            ->where( 'order_id', $order->id )
            ->whereKeyNot( $assignment->id )
            ->with( 'substatus' )
            ->get()
            ->pluck( 'substatus' );

        $status = in_array( $target->system_status, OrderStatusMachine::FORWARD_STATUSES, true )
            ? $this->statuses->rollUpBoardStatus( [ ...$siblings->all(), $target ] )
            : (string) $target->system_status;

        if ( null === $status || $status === $order->system_status ) {
            return $order;
        }

        $order = $this->statuses->transition( $order, $status, $actorUserId, $reason ?? __( 'Kanban card moved' ) );

        $current = null === $order->substatus_id ? null : OrderSubstatus::query()->find( $order->substatus_id );

        if ( null === $current || $current->system_status !== $status ) {
            $default = OrderSubstatus::query()->where( 'system_status', $status )->orderBy( 'position' )->orderBy( 'id' )->first();

            if ( null !== $default ) {
                $order = $this->statuses->setSubstatus( $order, $default, $actorUserId, __( 'Kanban status roll-up' ) );
            }
        }

        return $order;
    }
}
