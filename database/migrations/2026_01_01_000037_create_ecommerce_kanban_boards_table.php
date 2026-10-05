<?php

/**
 * Creates the `ecommerce_kanban_boards` table.
 *
 * A kanban board (engine spec §3.28, parent plan §9.1). `routing_rules` is a
 * condition tree deciding which placed orders land on the board; an empty
 * tree catches every order. `settings` holds board-level defaults such as
 * the card widgets used by columns that don't choose their own.
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
        Schema::create( 'ecommerce_kanban_boards', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'key', 120 );
            $table->string( 'name' );
            $table->text( 'description' )->nullable();
            $table->json( 'routing_rules' );
            $table->boolean( 'is_default' )->default( false );
            $table->boolean( 'is_active' )->default( true );
            $table->unsignedInteger( 'position' )->default( 0 );
            $table->json( 'settings' );
            $table->timestamps();

            $table->unique( 'key', 'ecommerce_kanban_boards_key_uk' );
            $table->index( [ 'is_active', 'position' ], 'ecommerce_kanban_boards_active_position_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_kanban_boards' );
    }
};
