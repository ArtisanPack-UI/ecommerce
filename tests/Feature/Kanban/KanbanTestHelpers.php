<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Models\Product;
use Illuminate\Support\Carbon;

if ( ! function_exists( 'kanbanBoard' ) ) {
    /**
     * A board with one column per `[ system_status, label, terminal? ]` entry,
     * in the given order. Returns the board with `columns.substatus` loaded.
     *
     * @param  array<string, mixed>                           $attributes  Board columns.
     * @param  array<int, array{0: string, 1: string, 2?: bool}>  $columns     Column specs.
     */
    function kanbanBoard( array $attributes = [], array $columns = [ [ 'pending', 'New' ], [ 'processing', 'Working' ], [ 'complete', 'Done', true ] ] ): KanbanBoard
    {
        $board = KanbanBoard::factory()->create( $attributes );

        foreach ( array_values( $columns ) as $position => $spec ) {
            $substatus = OrderSubstatus::factory()->forSystemStatus( $spec[0] )->create( [
                'label'       => $spec[1],
                'is_terminal' => (bool) ( $spec[2] ?? false ),
            ] );

            KanbanColumn::factory()->create( [ 'board_id' => $board->id, 'substatus_id' => $substatus->id, 'position' => $position ] );
        }

        return $board->load( 'columns.substatus' );
    }
}

if ( ! function_exists( 'kanbanColumn' ) ) {
    /**
     * The column on `$board` labelled `$label`.
     */
    function kanbanColumn( KanbanBoard $board, string $label ): KanbanColumn
    {
        return $board->columns()->with( 'substatus', 'board' )->get()->first( fn ( KanbanColumn $column ): bool => $column->substatus->label === $label );
    }
}

if ( ! function_exists( 'kanbanOrder' ) ) {
    /**
     * An order with one line per product type in `$types`.
     *
     * @param  array<string, mixed>  $attributes  Order columns.
     * @param  array<int, string>    $types       Product type per line.
     */
    function kanbanOrder( array $attributes = [], array $types = [ 'simple' ] ): Order
    {
        $order = Order::factory()->create( $attributes );

        foreach ( $types as $type ) {
            OrderItem::factory()->create( [
                'order_id'   => $order->id,
                'product_id' => Product::factory()->create( [ 'type' => $type ] )->id,
            ] );
        }

        return $order->fresh();
    }
}

if ( ! function_exists( 'putOnBoard' ) ) {
    /**
     * Places `$order`'s card in `$column` directly (no service, no hooks).
     */
    function putOnBoard( Order $order, KanbanColumn $column ): OrderBoardAssignment
    {
        return OrderBoardAssignment::factory()->create( [
            'order_id'     => $order->id,
            'board_id'     => $column->board_id,
            'substatus_id' => $column->substatus_id,
            'assigned_at'  => Carbon::now(),
            'moved_at'     => Carbon::now(),
        ] );
    }
}
