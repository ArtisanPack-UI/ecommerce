<?php

/**
 * Creates the `ecommerce_order_board_assignments` table.
 *
 * Which boards an order is on (engine spec §3.28, parent plan §9.2). An
 * order may be on any number of boards at once, each assignment carrying
 * its own `substatus_id` (the card's column). `removed_at` soft-removes
 * an assignment so the (order, board) pair stays unique; `moved_at`
 * records when the card entered its current column (days-in-column).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create( 'ecommerce_order_board_assignments', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'order_id' );
            $table->unsignedBigInteger( 'board_id' );
            $table->unsignedBigInteger( 'substatus_id' );
            $table->timestamp( 'assigned_at' );
            $table->timestamp( 'moved_at' )->nullable();
            $table->timestamp( 'removed_at' )->nullable();

            $table->unique( [ 'order_id', 'board_id' ], 'ecommerce_order_board_assignments_uk' );
            $table->index( [ 'board_id', 'substatus_id' ], 'ecommerce_order_board_assignments_board_substatus_idx' );
            $table->index( 'substatus_id', 'ecommerce_order_board_assignments_substatus_idx' );

            $table->foreign( 'order_id', 'ecommerce_order_board_assignments_order_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_orders' )
                ->cascadeOnDelete();
            $table->foreign( 'board_id', 'ecommerce_order_board_assignments_board_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_kanban_boards' )
                ->cascadeOnDelete();
            $table->foreign( 'substatus_id', 'ecommerce_order_board_assignments_substatus_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_order_substatuses' )
                ->cascadeOnDelete();
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_order_board_assignments' );
    }
};
