<?php

/**
 * Creates the `shipments` table (engine spec §3.25).
 *
 * `label_id` is a soft reference to the `shipping-labels` peer package —
 * checked at runtime, never constrained here.
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
        Schema::create( 'shipments', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'order_id' );
            $table->string( 'method_key', 120 );
            $table->string( 'carrier', 120 )->nullable();
            $table->string( 'service', 120 )->nullable();
            $table->string( 'tracking_number', 255 )->nullable();
            $table->string( 'tracking_url', 500 )->nullable();
            $table->unsignedBigInteger( 'label_id' )->nullable();
            $table->string( 'status', 60 );
            $table->timestamp( 'shipped_at' )->nullable();
            $table->timestamp( 'delivered_at' )->nullable();
            $table->json( 'meta' )->nullable();
            $table->timestamps();

            $table->index( 'order_id', 'shipments_order_idx' );
            $table->index( 'status', 'shipments_status_idx' );

            $table->foreign( 'order_id', 'shipments_order_fk' )
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
        Schema::dropIfExists( 'shipments' );
    }
};
