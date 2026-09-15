<?php

/**
 * Creates the `refund_items` table (engine spec §3.21).
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
        Schema::create( 'refund_items', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'refund_id' );
            $table->unsignedBigInteger( 'order_item_id' );
            $table->unsignedInteger( 'quantity' );
            $table->bigInteger( 'amount' );
            $table->char( 'currency', 3 );
            $table->boolean( 'restock' )->default( false );

            $table->index( 'refund_id', 'refund_items_refund_idx' );
            $table->index( 'order_item_id', 'refund_items_order_item_idx' );

            $table->foreign( 'refund_id', 'refund_items_refund_fk' )
                ->references( 'id' )
                ->on( 'refunds' )
                ->cascadeOnDelete();

            $table->foreign( 'order_item_id', 'refund_items_order_item_fk' )
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
        Schema::dropIfExists( 'refund_items' );
    }
};
