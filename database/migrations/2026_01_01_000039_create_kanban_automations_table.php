<?php

/**
 * Creates the `kanban_automations` table.
 *
 * Data-driven automations fired when a card moves (engine spec §3.28,
 * parent plan §9.4). `from_column_id` null means "from any column";
 * `trigger_key` names a KanbanAutomationRegistry entry and `conditions`
 * is an optional condition tree evaluated against the order.
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
        Schema::create( 'kanban_automations', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'board_id' );
            $table->unsignedBigInteger( 'from_column_id' )->nullable();
            $table->unsignedBigInteger( 'to_column_id' );
            $table->string( 'trigger_key', 120 );
            $table->json( 'trigger_config' );
            $table->json( 'conditions' );
            $table->boolean( 'is_active' )->default( true );
            $table->timestamps();

            $table->index( 'board_id', 'kanban_automations_board_idx' );
            $table->index( [ 'from_column_id', 'to_column_id' ], 'kanban_automations_move_idx' );
            $table->foreign( 'board_id', 'kanban_automations_board_fk' )
                ->references( 'id' )
                ->on( 'kanban_boards' )
                ->cascadeOnDelete();
            $table->foreign( 'from_column_id', 'kanban_automations_from_fk' )
                ->references( 'id' )
                ->on( 'kanban_columns' )
                ->cascadeOnDelete();
            $table->foreign( 'to_column_id', 'kanban_automations_to_fk' )
                ->references( 'id' )
                ->on( 'kanban_columns' )
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
        Schema::dropIfExists( 'kanban_automations' );
    }
};
