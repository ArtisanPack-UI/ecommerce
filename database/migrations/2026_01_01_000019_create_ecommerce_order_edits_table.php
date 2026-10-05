<?php

/**
 * Creates the `ecommerce_order_edits` table (engine spec §3.20).
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
        Schema::create( 'ecommerce_order_edits', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'order_id' );
            $table->unsignedBigInteger( 'actor_user_id' )->nullable();
            $table->string( 'reason', 255 )->nullable();
            $table->json( 'diff' );
            $table->json( 'pre_edit_snapshot' );
            $table->timestamp( 'created_at' )->nullable();

            $table->index( [ 'order_id', 'created_at' ], 'ecommerce_order_edits_order_time_idx' );

            $table->foreign( 'order_id', 'ecommerce_order_edits_order_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_orders' )
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
        Schema::dropIfExists( 'ecommerce_order_edits' );
    }
};
