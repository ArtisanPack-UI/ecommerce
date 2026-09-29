<?php

/**
 * OrderStatusMachine.
 *
 * Owns the two-tier order-status contract:
 *
 * 1. **System status** (`orders.system_status`) drives business logic and is
 *    constrained by the transition graph declared in {@see self::ALLOWED_TRANSITIONS}.
 *    Illegal moves throw {@see InvalidOrderStatusTransitionException}.
 * 2. **Sub-status** (`orders.substatus_id`, or a per-board sub-status attached
 *    on `order_board_assignments`) is user-defined and drives kanban columns.
 *    Sub-status transitions are unrestricted by default; store owners can add
 *    validation by returning `false` from
 *    `ap.ecommerce.order.canTransitionSubstatus`.
 *
 * The machine also exposes {@see self::assertBoardSubstatusCompatible()} so any
 * service that assigns an order to a board's column can verify the column's
 * sub-status belongs to a `system_status` compatible with the order's current
 * one before writing the assignment row. Violations throw
 * {@see IncompatibleBoardSubstatusException} at the service boundary. Plan
 * §5.7 / §9.2.
 *
 * Kanban boards relax that rule within the forward chain
 * ({@see self::FORWARD_STATUSES}): an order can be "Printing" (processing)
 * on a production board while already "Shipped" (complete) on a shipping
 * board. The order's own status is the roll-up of its boards
 * ({@see self::rollUpBoardStatus()}) — the most-progressed board wins,
 * except that `complete` needs every board on a terminal `complete`
 * sub-status. Exit statuses (cancelled, refunded, failed) always match
 * exactly.
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

use ArtisanPackUI\Ecommerce\Events\OrderStatusChanged;
use ArtisanPackUI\Ecommerce\Events\OrderSubstatusChanged;
use ArtisanPackUI\Ecommerce\Exceptions\IncompatibleBoardSubstatusException;
use ArtisanPackUI\Ecommerce\Exceptions\InvalidOrderStatusTransitionException;
use ArtisanPackUI\Ecommerce\Exceptions\SubstatusTransitionRejectedException;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderStatusMachine
{
    /**
     * Directed graph of allowed `system_status` transitions.
     *
     * Same-status "transitions" are treated as no-ops before the graph is
     * consulted, so this list only names the edges that must actually move
     * the order to a new state. Every core system status seeded by
     * migration 2026_01_01_000014 appears here.
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        'pending'    => [ 'processing', 'cancelled', 'failed' ],
        'processing' => [ 'complete', 'cancelled', 'refunded', 'failed' ],
        'complete'   => [ 'refunded' ],
        'cancelled'  => [ 'refunded' ],
        'refunded'   => [],
        'failed'     => [ 'pending', 'cancelled' ],
    ];

    /**
     * System statuses an order progresses through, in order. Kanban board
     * assignments may sit anywhere in this chain relative to the order.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const FORWARD_STATUSES = [ 'pending', 'processing', 'complete' ];

    /**
     * Moves `$order` to the given `system_status`.
     *
     * Rejects moves not declared in {@see self::ALLOWED_TRANSITIONS}. Same-status
     * calls are treated as no-ops. On success: writes an `order.status_changed`
     * timeline entry, fires `ap.ecommerce.order.statusChanged`, and dispatches
     * {@see OrderStatusChanged}.
     *
     * @since 1.0.0
     *
     * @param  Order        $order        The order to transition. Callers should pass a freshly-loaded instance.
     * @param  string       $to           Target `system_status`.
     * @param  int|null     $actorUserId  Auth user id driving the change; `null` for system.
     * @param  string|null  $reason       Free-text reason logged on the timeline row.
     *
     * @throws InvalidOrderStatusTransitionException When the transition is not declared allowed.
     *
     * @return Order The refreshed order.
     */
    public function transition(
        Order $order,
        string $to,
        ?int $actorUserId = null,
        ?string $reason = null,
    ): Order {
        return DB::transaction( function () use ( $order, $to, $actorUserId, $reason ): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );
            $from   = (string) $locked->system_status;

            if ( $from === $to ) {
                return $locked;
            }

            if ( ! $this->isTransitionAllowed( $from, $to ) ) {
                throw new InvalidOrderStatusTransitionException( $from, $to );
            }

            $locked->system_status = $to;
            $locked->save();

            $refreshed = $locked->fresh() ?? $locked;

            OrderTimelineEntry::query()->create( [
                'order_id'      => $refreshed->id,
                'actor_user_id' => $actorUserId,
                'event_type'    => 'order.status_changed',
                'payload'       => [
                    'from'   => $from,
                    'to'     => $to,
                    'reason' => $reason,
                ],
            ] );

            doAction( 'ap.ecommerce.order.statusChanged', $refreshed, $from, $to );
            Event::dispatch( new OrderStatusChanged( $refreshed, $from, $to ) );

            return $refreshed;
        } );
    }

    /**
     * Changes the sub-status on `$order` — either its global default
     * (`orders.substatus_id`) or the sub-status the order carries on a
     * specific kanban board.
     *
     * When `$boardId` is null (the default), the change updates
     * `orders.substatus_id` and the target sub-status must belong to the
     * order's own `system_status`. When `$boardId` is provided, the caller is
     * responsible for writing the `order_board_assignments` row itself; this
     * method only checks board compatibility
     * ({@see self::assertBoardAssignmentCompatible()}), fires the hook,
     * writes the timeline entry, and dispatches the event.
     *
     * **Board-move callers must wrap the assignment write and this call in the
     * same outer `DB::transaction`** so the timeline row and the assignment row
     * commit or roll back together. Laravel's nested transactions join the
     * outer scope, so this method's internal `DB::transaction` participates
     * cleanly. Otherwise a caller crash between the two writes leaves a
     * timeline entry the nightly audit cannot see (there is no assignment row
     * to compare against).
     *
     * Sub-status transitions are unrestricted by default. Store owners can
     * gate them with `ap.ecommerce.order.canTransitionSubstatus`; returning
     * a falsy value from the filter throws {@see SubstatusTransitionRejectedException} at the
     * service boundary.
     *
     * @since 1.0.0
     *
     * @param  Order           $order        The order to change.
     * @param  OrderSubstatus  $to           The sub-status to set.
     * @param  int|null        $actorUserId  Auth user id driving the change; `null` for system.
     * @param  string|null     $reason       Free-text reason logged on the timeline row.
     * @param  int|null        $boardId      Kanban board id whose column drove this change, or `null` for global default.
     *
     * @throws IncompatibleBoardSubstatusException When the sub-status belongs to a system_status the order is not on.
     * @throws SubstatusTransitionRejectedException When a canTransitionSubstatus filter vetoes the change.
     *
     * @return Order The refreshed order.
     */
    public function setSubstatus(
        Order $order,
        OrderSubstatus $to,
        ?int $actorUserId = null,
        ?string $reason = null,
        ?int $boardId = null,
    ): Order {
        return DB::transaction( function () use ( $order, $to, $actorUserId, $reason, $boardId ): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );

            null === $boardId
                ? $this->assertBoardSubstatusCompatible( $locked, $to )
                : $this->assertBoardAssignmentCompatible( $locked, $to );

            $from = null !== $locked->substatus_id
                ? OrderSubstatus::query()->find( $locked->substatus_id )
                : null;

            $allowed = (bool) applyFilters(
                'ap.ecommerce.order.canTransitionSubstatus',
                true,
                $locked,
                $from,
                $to,
                $boardId,
            );

            if ( ! $allowed ) {
                throw new SubstatusTransitionRejectedException( __( 'Sub-status transition on order :order to ":substatus" was rejected by a canTransitionSubstatus filter.', [
                    'order'     => $locked->id,
                    'substatus' => $to->key,
                ] ) );
            }

            if ( null === $boardId && (int) $to->id === (int) $locked->substatus_id ) {
                return $locked;
            }

            if ( null === $boardId ) {
                $locked->substatus_id = $to->id;
                $locked->save();
            }

            $refreshed = $locked->fresh() ?? $locked;

            OrderTimelineEntry::query()->create( [
                'order_id'      => $refreshed->id,
                'actor_user_id' => $actorUserId,
                'event_type'    => 'order.substatus_changed',
                'payload'       => [
                    'from_id'  => $from?->id,
                    'from_key' => $from?->key,
                    'to_id'    => $to->id,
                    'to_key'   => $to->key,
                    'board_id' => $boardId,
                    'reason'   => $reason,
                ],
            ] );

            doAction( 'ap.ecommerce.order.substatusChanged', $refreshed, $from, $to, $boardId );
            Event::dispatch( new OrderSubstatusChanged( $refreshed, $from, $to, $boardId ) );

            return $refreshed;
        } );
    }

    /**
     * Verifies `$substatus->system_status` matches `$order->system_status`.
     *
     * Called by every code path that assigns a sub-status to an order — the
     * order's global default in {@see self::setSubstatus()}, and any future
     * board-assignment service that writes to `order_board_assignments`. This
     * is the guard that keeps the two tiers consistent at the service boundary.
     *
     * @since 1.0.0
     *
     * @param  Order           $order
     * @param  OrderSubstatus  $substatus
     *
     * @throws IncompatibleBoardSubstatusException
     *
     * @return void
     */
    public function assertBoardSubstatusCompatible( Order $order, OrderSubstatus $substatus ): void
    {
        if ( (string) $substatus->system_status === (string) $order->system_status ) {
            return;
        }

        throw new IncompatibleBoardSubstatusException(
            (int) $order->id,
            (string) $order->system_status,
            (int) $substatus->id,
            (string) $substatus->system_status,
        );
    }

    /**
     * Whether a kanban board assignment may sit on `$substatus` while the
     * order is on its current `system_status`: the statuses match, or both
     * are in {@see self::FORWARD_STATUSES} (boards may lead or lag the
     * order within the forward chain).
     *
     * @since 1.0.0
     *
     * @param  Order           $order      Order.
     * @param  OrderSubstatus  $substatus  Board sub-status.
     *
     * @return bool
     */
    public function isBoardAssignmentCompatible( Order $order, OrderSubstatus $substatus ): bool
    {
        $orderStatus     = (string) $order->system_status;
        $substatusStatus = (string) $substatus->system_status;

        return $orderStatus === $substatusStatus
            || ( in_array( $orderStatus, self::FORWARD_STATUSES, true ) && in_array( $substatusStatus, self::FORWARD_STATUSES, true ) );
    }

    /**
     * Throwing form of {@see self::isBoardAssignmentCompatible()}.
     *
     * @since 1.0.0
     *
     * @param  Order           $order      Order.
     * @param  OrderSubstatus  $substatus  Board sub-status.
     *
     * @throws IncompatibleBoardSubstatusException
     *
     * @return void
     */
    public function assertBoardAssignmentCompatible( Order $order, OrderSubstatus $substatus ): void
    {
        if ( $this->isBoardAssignmentCompatible( $order, $substatus ) ) {
            return;
        }

        throw new IncompatibleBoardSubstatusException(
            (int) $order->id,
            (string) $order->system_status,
            (int) $substatus->id,
            (string) $substatus->system_status,
        );
    }

    /**
     * The order `system_status` implied by its kanban board sub-statuses
     * (parent plan §9.2): the most-progressed forward status across the
     * boards, where `complete` requires every board to be on a terminal
     * `complete` sub-status — otherwise a board that finished early holds
     * the order at `processing`. Returns `null` when there are no boards or
     * any board sits on an exit status (exit statuses are order-wide and
     * not rolled up).
     *
     * @since 1.0.0
     *
     * @param  iterable<OrderSubstatus>  $substatuses  One sub-status per active board assignment.
     *
     * @return string|null
     */
    public function rollUpBoardStatus( iterable $substatuses ): ?string
    {
        $highest     = -1;
        $allComplete = true;
        $count       = 0;

        foreach ( $substatuses as $substatus ) {
            $rank = array_search( (string) $substatus->system_status, self::FORWARD_STATUSES, true );

            if ( false === $rank ) {
                return null;
            }

            $count++;
            $highest     = max( $highest, $rank );
            $allComplete = $allComplete && 'complete' === $substatus->system_status && $substatus->is_terminal;
        }

        if ( 0 === $count ) {
            return null;
        }

        if ( $allComplete ) {
            return 'complete';
        }

        return self::FORWARD_STATUSES[ min( $highest, 1 ) ];
    }

    /**
     * Returns true when moving from `$from` to `$to` is one of the declared
     * transitions in {@see self::ALLOWED_TRANSITIONS}.
     *
     * @since 1.0.0
     *
     * @param  string  $from
     * @param  string  $to
     *
     * @return bool
     */
    public function isTransitionAllowed( string $from, string $to ): bool
    {
        $edges = self::ALLOWED_TRANSITIONS[ $from ] ?? null;

        if ( null === $edges ) {
            return false;
        }

        return in_array( $to, $edges, true );
    }
}
