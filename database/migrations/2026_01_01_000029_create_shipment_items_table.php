<?php

/**
 * Creates the `shipment_items` table (engine spec §3.25).
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
        Schema::create( 'shipment_items', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'shipment_id' );
            $table->unsignedBigInteger( 'order_item_id' );
            $table->unsignedInteger( 'quantity' );

            $table->index( 'shipment_id', 'shipment_items_shipment_idx' );
            $table->index( 'order_item_id', 'shipment_items_order_item_idx' );

            $table->foreign( 'shipment_id', 'shipment_items_shipment_fk' )
                ->references( 'id' )
                ->on( 'shipments' )
                ->cascadeOnDelete();

            $table->foreign( 'order_item_id', 'shipment_items_order_item_fk' )
                ->references( 'id' )
                ->on( 'order_items' )
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
        Schema::dropIfExists( 'shipment_items' );
    }
};
