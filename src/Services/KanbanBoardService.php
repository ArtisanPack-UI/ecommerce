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
 * where they are. Cards keep a manual position in their column; a move
 * takes an optional {@see KanbanCardPlacement}, and a same-column move with
 * one reorders the card without any of the status work above.
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
use ArtisanPackUI\Ecommerce\Events\KanbanCardReordered;
use ArtisanPackUI\Ecommerce\Exceptions\KanbanOperationException;
use ArtisanPackUI\Ecommerce\Kanban\KanbanCardPlacement;
use ArtisanPackUI\Ecommerce\Kanban\KanbanCardPositions;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
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
     * @since 1.2.0 Takes the card position writer.
     *
     * @param  OrderStatusMachine   $statuses   Order status transitions + board roll-up.
     * @param  KanbanCardPositions  $positions  Card positions within a column.
     */
    public function __construct(
        private readonly OrderStatusMachine $statuses,
        private readonly KanbanCardPositions $positions,
    ) {
    }

    /**
     * Moves `$order`'s card on `$to`'s board into `$to`.
     *
     * With a `$placement` the card lands at that place in the column (the
     * bottom otherwise). A move into the card's own column is a reorder when
     * a placement is given and a no-op without one: it changes no status,
     * skips the WIP check, runs no automations, and fires
     * `ap.ecommerce.kanban.cardReordered` / {@see KanbanCardReordered}.
     *
     * Locks are taken in a fixed order — the order, the source and target
     * columns (by id), then the card — so positions in a column are only
     * ever written by one transaction at a time.
     *
     * @since 1.0.0
     * @since 1.2.0 Takes a placement; same-column moves with one reorder the card.
     *
     * @param  Order                     $order        Order whose card moves.
     * @param  KanbanColumn              $to           Target column (its board is the board the card moves on).
     * @param  int|null                  $actorUserId  Acting user; `null` for system moves.
     * @param  string|null               $reason       Free-text reason for the timeline.
     * @param  KanbanCardPlacement|null  $placement    Where the card lands in the column; the bottom when null.
     *
     * @throws KanbanOperationException When the order isn't on the board, the move is vetoed, or the column is full.
     * @throws \ArtisanPackUI\Ecommerce\Exceptions\InvalidOrderStatusTransitionException When the roll-up would make an illegal status move.
     * @throws \ArtisanPackUI\Ecommerce\Exceptions\IncompatibleBoardSubstatusException When the column can't hold the order at its status.
     *
     * @return OrderBoardAssignment The updated assignment.
     */
    public function move( Order $order, KanbanColumn $to, ?int $actorUserId = null, ?string $reason = null, ?KanbanCardPlacement $placement = null ): OrderBoardAssignment
    {
        $result = DB::transaction( function () use ( $order, $to, $actorUserId, $reason, $placement ): ?array {
            // Lock the order before anything else — the same order
            // KanbanRoutingService::assign() uses — so the two can't deadlock.
            $locked     = Order::query()->lockForUpdate()->findOrFail( $order->id );
            $board      = KanbanBoard::query()->findOrFail( $to->board_id );
            $assignment = $this->activeAssignment( $order, $board );
            $from       = $assignment->currentColumn()
                ?? new KanbanColumn( [ 'board_id' => $board->id, 'substatus_id' => $assignment->substatus_id ] );

            [ $to, $placement ] = $this->filteredTarget( $assignment, $from, $to, $board, $actorUserId, $reason, $placement );

            $reorder = (int) $to->substatus_id === (int) $assignment->substatus_id;

            if ( $reorder && null === $placement ) {
                return null;
            }

            // Lock the columns before the card: positions in a column are
            // written only under its row lock, and moves in opposite
            // directions take the two columns in the same (id) order. The
            // target lock also stops two moves taking its last WIP slot.
            $to         = $this->lockColumns( $from, $to );
            $assignment = $this->activeAssignment( $order, $board, true );

            if ( $reorder || (int) $to->substatus_id === (int) $assignment->substatus_id ) {
                $placement ??= KanbanCardPlacement::bottom();

                return $this->positions->place( $assignment, $placement ) ? [ 'reordered', $assignment, $to, $board, $placement ] : null;
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
            $this->positions->place( $assignment, $placement ?? KanbanCardPlacement::bottom() );

            $this->statuses->setSubstatus( $locked, $target, $actorUserId, $reason, (int) $board->id );

            return [ 'moved', $assignment, $from, $to, $board ];
        } );

        if ( null === $result ) {
            return OrderBoardAssignment::query()->where( 'order_id', $order->id )->where( 'board_id', $to->board_id )->firstOrFail();
        }

        $refreshed = $order->fresh() ?? $order;

        if ( 'reordered' === $result[0] ) {
            [ , $assignment, $column, $board, $placement ] = $result;

            AfterCommit::action( 'ap.ecommerce.kanban.cardReordered', $refreshed, $column, $board, $placement );
            Event::dispatch( new KanbanCardReordered( $refreshed, $column, $board, $placement ) );

            return $assignment;
        }

        [ , $assignment, $from, $to, $board ] = $result;

        AfterCommit::action( 'ap.ecommerce.kanban.cardMoved', $refreshed, $from, $to, $board );
        Event::dispatch( new KanbanCardMoved( $refreshed, $from, $to, $board ) );

        return $assignment;
    }

    /**
     * Runs the `ap.ecommerce.kanban.cardMoving` filter and returns the
     * (possibly redirected) target column and placement.
     *
     * The filter receives the move as an array whose `placement` is
     * `[ 'mode' => 'top'|'bottom'|'before'|'after', 'order_id' => ?int ]` or
     * null; it may change `to_column_id` and `placement`, or return null to
     * veto the move.
     *
     * @since 1.0.0
     * @since 1.2.0 Takes and returns the placement.
     *
     * @param  OrderBoardAssignment      $assignment   Card being moved.
     * @param  KanbanColumn              $from         Current column.
     * @param  KanbanColumn              $to           Requested column.
     * @param  KanbanBoard               $board        Board.
     * @param  int|null                  $actorUserId  Acting user.
     * @param  string|null               $reason       Reason.
     * @param  KanbanCardPlacement|null  $placement    Requested placement.
     *
     * @throws KanbanOperationException When the filter vetoes the move or redirects it off the board.
     *
     * @return array{0: KanbanColumn, 1: KanbanCardPlacement|null}
     */
    protected function filteredTarget(
        OrderBoardAssignment $assignment,
        KanbanColumn $from,
        KanbanColumn $to,
        KanbanBoard $board,
        ?int $actorUserId,
        ?string $reason,
        ?KanbanCardPlacement $placement = null,
    ): array {
        $move = applyFilters( 'ap.ecommerce.kanban.cardMoving', [
            'order_id'       => (int) $assignment->order_id,
            'board_id'       => (int) $board->id,
            'from_column_id' => $from->exists ? (int) $from->id : null,
            'to_column_id'   => (int) $to->id,
            'actor_user_id'  => $actorUserId,
            'reason'         => $reason,
            'placement'      => $placement?->toArray(),
        ], $assignment );

        if ( ! is_array( $move ) || ! is_numeric( $move['to_column_id'] ?? null ) ) {
            throw new KanbanOperationException( 'move-rejected', __( 'The card move was rejected.' ) );
        }

        $placement = KanbanCardPlacement::fromArray( $move['placement'] ?? null );

        if ( (int) $move['to_column_id'] === (int) $to->id ) {
            return [ $to, $placement ];
        }

        $redirected = KanbanColumn::query()->where( 'board_id', $board->id )->find( (int) $move['to_column_id'] );

        if ( null === $redirected ) {
            throw new KanbanOperationException( 'column-not-on-board', __( 'Column :column is not on board ":board".', [ 'column' => $move['to_column_id'], 'board' => $board->name ] ) );
        }

        return [ $redirected, $placement ];
    }

    /**
     * `$order`'s active card on `$board`.
     *
     * @since 1.2.0
     *
     * @param  Order        $order  Order.
     * @param  KanbanBoard  $board  Board.
     * @param  bool         $lock   Lock the row.
     *
     * @throws KanbanOperationException When the order isn't on the board.
     *
     * @return OrderBoardAssignment
     */
    protected function activeAssignment( Order $order, KanbanBoard $board, bool $lock = false ): OrderBoardAssignment
    {
        $assignment = OrderBoardAssignment::query()
            ->active()
            ->where( 'order_id', $order->id )
            ->where( 'board_id', $board->id )
            ->when( $lock, static fn ( $query ) => $query->lockForUpdate() )
            ->first();

        if ( null === $assignment ) {
            throw new KanbanOperationException( 'not-on-board', __( 'Order :order is not on board ":board".', [ 'order' => $order->id, 'board' => $board->name ] ) );
        }

        return $assignment;
    }

    /**
     * Locks the source and target columns' rows, lowest id first, and returns
     * the locked target.
     *
     * @since 1.2.0
     *
     * @param  KanbanColumn  $from  Source column (may be unsaved when the card's column was deleted).
     * @param  KanbanColumn  $to    Target column.
     *
     * @return KanbanColumn
     */
    protected function lockColumns( KanbanColumn $from, KanbanColumn $to ): KanbanColumn
    {
        if ( ! $to->exists ) {
            return $to;
        }

        $ids = array_values( array_unique( array_filter( [ $from->exists ? (int) $from->id : null, (int) $to->id ] ) ) );
        sort( $ids );

        $locked = KanbanColumn::query()->whereKey( $ids )->orderBy( 'id' )->lockForUpdate()->get()->keyBy( 'id' );

        return $locked->get( (int) $to->id ) ?? KanbanColumn::query()->findOrFail( $to->getKey() );
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
