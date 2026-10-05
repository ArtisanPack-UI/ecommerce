<?php

/**
 * Creates the `ecommerce_kanban_columns` table.
 *
 * One column on a kanban board (engine spec §3.28). Each column is 1:1 with
 * an order sub-status; a card's column is its assignment's `substatus_id`.
 * `card_widgets` is the ordered list of widget registry keys rendered on
 * cards in this column.
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
        Schema::create( 'ecommerce_kanban_columns', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'board_id' );
            $table->unsignedBigInteger( 'substatus_id' );
            $table->string( 'label_override', 120 )->nullable();
            $table->char( 'color_override', 7 )->nullable();
            $table->string( 'icon_override', 80 )->nullable();
            $table->unsignedInteger( 'position' )->default( 0 );
            $table->unsignedInteger( 'wip_limit' )->nullable();
            $table->json( 'card_widgets' );
            $table->timestamps();

            $table->unique( [ 'board_id', 'substatus_id' ], 'ecommerce_kanban_columns_board_substatus_uk' );
            $table->index( 'board_id', 'ecommerce_kanban_columns_board_idx' );
            $table->foreign( 'board_id', 'ecommerce_kanban_columns_board_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_kanban_boards' )
                ->cascadeOnDelete();
            $table->foreign( 'substatus_id', 'ecommerce_kanban_columns_substatus_fk' )
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
        Schema::dropIfExists( 'ecommerce_kanban_columns' );
    }
};
