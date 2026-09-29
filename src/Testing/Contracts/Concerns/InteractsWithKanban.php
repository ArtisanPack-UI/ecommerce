<?php

/**
 * InteractsWithKanban.
 *
 * Fixtures shared by the kanban contract suites: a placed order with
 * lines, a board + column holding it, and an automation on that column.
 * Pulls in {@see InteractsWithEcommerceCarts} for the Testbench provider
 * + database setup and the registry-key pattern.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Contracts\Concerns;

use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
trait InteractsWithKanban
{
    use InteractsWithEcommerceCarts;

    /**
     * A persisted order with `$lines` lines (each quantity 1).
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  Order columns.
     * @param  int                   $lines       Number of lines.
     *
     * @return Order
     */
    protected function makeKanbanOrder( array $attributes = [], int $lines = 2 ): Order
    {
        $order = Order::factory()->create( array_merge( [ 'system_status' => 'processing' ], $attributes ) );

        if ( $lines > 0 ) {
            OrderItem::factory()->count( $lines )->create( [ 'order_id' => $order->id ] );
        }

        return $order->fresh() ?? $order;
    }

    /**
     * A board with one column (on a sub-status of the order's status) and
     * `$order`'s card in it.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order   Order to place on the board.
     * @param  array<string, mixed>  $column  Column columns.
     *
     * @return KanbanColumn
     */
    protected function makeKanbanColumnFor( Order $order, array $column = [] ): KanbanColumn
    {
        $board     = KanbanBoard::factory()->create();
        $substatus = OrderSubstatus::factory()->forSystemStatus( (string) $order->system_status )->create();
        $created   = KanbanColumn::factory()->create( array_merge( [ 'board_id' => $board->id, 'substatus_id' => $substatus->id ], $column ) );

        OrderBoardAssignment::factory()->create( [
            'order_id'     => $order->id,
            'board_id'     => $board->id,
            'substatus_id' => $substatus->id,
            'assigned_at'  => Carbon::now(),
            'moved_at'     => Carbon::now(),
        ] );

        return $created->load( 'board', 'substatus' );
    }

    /**
     * An automation firing `$triggerKey` when a card enters `$column`.
     *
     * @since 1.0.0
     *
     * @param  KanbanColumn          $column      Target column.
     * @param  string                $triggerKey  Trigger registry key.
     * @param  array<string, mixed>  $config      Trigger config.
     *
     * @return KanbanAutomation
     */
    protected function makeKanbanAutomation( KanbanColumn $column, string $triggerKey, array $config = [] ): KanbanAutomation
    {
        return KanbanAutomation::factory()->create( [
            'board_id'       => $column->board_id,
            'to_column_id'   => $column->id,
            'trigger_key'    => $triggerKey,
            'trigger_config' => $config,
        ] )->load( 'board', 'toColumn' );
    }
}
