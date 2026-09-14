<?php

/**
 * Creates the `inventory_reservations` table (engine spec §3.12).
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
        Schema::create( 'inventory_reservations', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'inventory_item_id' );
            $table->string( 'reservable_type', 191 );
            $table->unsignedBigInteger( 'reservable_id' );
            $table->unsignedInteger( 'quantity' );
            $table->timestamp( 'expires_at' )->nullable();
            $table->timestamps();

            $table->index( 'expires_at', 'inventory_reservations_expires_idx' );
            $table->index(
                [ 'reservable_type', 'reservable_id' ],
                'inventory_reservations_reservable_idx',
            );

            $table->foreign( 'inventory_item_id', 'inventory_reservations_item_fk' )
                ->references( 'id' )
                ->on( 'inventory_items' )
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
        Schema::dropIfExists( 'inventory_reservations' );
    }
};
