<?php

/**
 * Creates the `inventory_items` table (engine spec §3.11).
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
        Schema::create( 'inventory_items', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'stockable_type', 191 );
            $table->unsignedBigInteger( 'stockable_id' );
            $table->boolean( 'track_inventory' )->default( true );
            $table->integer( 'quantity_on_hand' )->default( 0 );
            $table->unsignedInteger( 'quantity_reserved' )->default( 0 );
            $table->boolean( 'allow_backorder' )->default( false );
            $table->unsignedInteger( 'low_stock_threshold' )->nullable();
            $table->unsignedBigInteger( 'warehouse_id' )->nullable();
            $table->timestamps();

            $table->unique(
                [ 'stockable_type', 'stockable_id', 'warehouse_id' ],
                'inventory_items_stockable_uk',
            );
            $table->index(
                [ 'stockable_type', 'stockable_id' ],
                'inventory_items_stockable_idx',
            );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'inventory_items' );
    }
};
