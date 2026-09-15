<?php

/**
 * Creates the `order_timeline_entries` table (engine spec §3.19).
 *
 * Append-only activity log for orders.
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
        Schema::create( 'order_timeline_entries', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'order_id' );
            $table->unsignedBigInteger( 'actor_user_id' )->nullable();
            $table->string( 'event_type', 120 );
            $table->json( 'payload' )->nullable();
            $table->timestamp( 'created_at' )->nullable();

            $table->index( [ 'order_id', 'created_at' ], 'order_timeline_entries_order_time_idx' );

            $table->foreign( 'order_id', 'order_timeline_entries_order_fk' )
                ->references( 'id' )
                ->on( 'orders' )
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
        Schema::dropIfExists( 'order_timeline_entries' );
    }
};
