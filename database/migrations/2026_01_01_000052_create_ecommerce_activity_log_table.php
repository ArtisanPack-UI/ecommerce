<?php

/**
 * Creates the `ecommerce_activity_log` table.
 *
 * Append-only, polymorphic activity log for entities other than orders
 * (orders keep `order_timeline_entries`). Every product, variant, price,
 * inventory, customer, customer-note, promotion, and coupon write records
 * one row against its owning top-level entity — the product, customer, or
 * promotion — so one subject's history shows everything that touched it.
 * Parent plan §10.2 item 12.
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
        Schema::create( 'ecommerce_activity_log', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'subject_type', 191 );
            $table->unsignedBigInteger( 'subject_id' );
            $table->unsignedBigInteger( 'actor_user_id' )->nullable();
            $table->string( 'event_type', 120 );
            $table->json( 'payload' )->nullable();
            $table->timestamp( 'created_at' )->nullable();

            $table->index( [ 'subject_type', 'subject_id', 'created_at' ], 'ecommerce_activity_log_subject_time_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_activity_log' );
    }
};
