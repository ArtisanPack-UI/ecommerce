<?php

/**
 * KanbanCardRefresher.
 *
 * Tells every open board an order is on that its card changed
 * (ArtisanPack-UI/ecommerce#201). Broadcasts a `card-updated`
 * {@see \ArtisanPackUI\Ecommerce\Broadcasting\KanbanBoardBroadcast} —
 * `{ type: 'card-updated', board_id, order_id, column_id, widget_key }` —
 * on each active board's private channel, after commit.
 *
 * The core listens for payment and fulfillment status changes, order edits,
 * new shipments, and `meta.tags` changes. A satellite whose widget reads its
 * own data calls the same helper:
 *
 * ```php
 * app( KanbanCardRefresher::class )->refresh( $order, 'printful:status' );
 * ```
 *
 * Refreshes are debounced until they go out (one instance per request or
 * job): while a refresh waits for its transaction to commit, the same order
 * and widget key are queued once, and widget refreshes for an order with a
 * whole-card refresh (`$widgetKey` null) waiting are dropped. Once sent, a
 * later change queues a new refresh. A no-op while `artisanpack.ecommerce.kanban.broadcast` is off.
 *
 * Widgets don't need their own channels for this: refreshes ride the
 * authorized board channel, and core widgets return `null` from
 * `refreshSubscription()`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Kanban;

use ArtisanPackUI\Ecommerce\Listeners\BroadcastKanbanBoardEvents;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
class KanbanCardRefresher
{
    /**
     * Refreshes already queued this request, keyed `"{order}|{widget}"`
     * (`*` for the whole card).
     *
     * @since 1.2.0
     *
     * @var array<string, true>
     */
    private array $queued = [];

    /**
     * Queues a `card-updated` broadcast for `$order` on each active board
     * it's on.
     *
     * @since 1.2.0
     *
     * @param  int|Order    $order      Order or order id.
     * @param  string|null  $widgetKey  The one widget that changed; null for the whole card.
     *
     * @return bool Whether a broadcast was queued (false when off or debounced).
     */
    public function refresh( Order|int $order, ?string $widgetKey = null ): bool
    {
        if ( ! (bool) config( 'artisanpack.ecommerce.kanban.broadcast', false ) ) {
            return false;
        }

        $orderId = $order instanceof Order ? (int) $order->getKey() : $order;
        $key     = $orderId . '|' . ( $widgetKey ?? '*' );

        if ( isset( $this->queued[ $orderId . '|*' ] ) || isset( $this->queued[ $key ] ) ) {
            return false;
        }

        $this->queued[ $key ] = true;

        AfterCommit::run( 'kanban.card-updated', function () use ( $orderId, $widgetKey, $key ): void {
            // Sent: a later change in this request (another transaction) refreshes again.
            unset( $this->queued[ $key ] );

            $assignments = OrderBoardAssignment::query()
                ->active()
                ->where( 'order_id', $orderId )
                ->whereHas( 'board', static fn ( $query ) => $query->where( 'is_active', true ) )
                ->get( [ 'board_id', 'substatus_id' ] );

            foreach ( $assignments as $assignment ) {
                $columnId = KanbanColumn::query()
                    ->where( 'board_id', $assignment->board_id )
                    ->where( 'substatus_id', $assignment->substatus_id )
                    ->value( 'id' );

                BroadcastKanbanBoardEvents::send( 'card-updated', (int) $assignment->board_id, [
                    'order_id'   => $orderId,
                    'column_id'  => null === $columnId ? null : (int) $columnId,
                    'widget_key' => $widgetKey,
                ] );
            }
        } );

        return true;
    }

    /**
     * Refreshes the card when a saved order changed something the core
     * widgets show: payment status, fulfillment status, or `meta.tags`.
     *
     * @since 1.2.0
     *
     * @param  Order  $order  Order that was just updated.
     *
     * @return void
     */
    public function orderUpdated( Order $order ): void
    {
        $tagsBefore = (array) ( ( (array) $order->getOriginal( 'meta' ) )['tags'] ?? [] );
        $tagsAfter  = (array) ( ( (array) $order->meta )['tags'] ?? [] );

        if ( $order->wasChanged( [ 'payment_status', 'fulfillment_status', 'total_amount', 'total_refunded_amount' ] ) || $tagsBefore !== $tagsAfter ) {
            $this->refresh( $order );
        }
    }
}
