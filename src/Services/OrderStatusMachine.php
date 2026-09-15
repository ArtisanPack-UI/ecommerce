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
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

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
     * `orders.substatus_id` and the target sub-status must belong to a
     * `system_status` compatible with `$order->system_status`. When `$boardId`
     * is provided, the caller is responsible for writing the
     * `order_board_assignments` row itself; this method only checks board
     * compatibility, fires the hook, writes the timeline entry, and dispatches
     * the event.
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
     * a falsy value from the filter throws {@see RuntimeException} at the
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

            $this->assertBoardSubstatusCompatible( $locked, $to );

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
                throw new RuntimeException( sprintf(
                    'Sub-status transition on order %d to "%s" was rejected by a canTransitionSubstatus filter.',
                    $locked->id,
                    $to->key,
                ) );
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
