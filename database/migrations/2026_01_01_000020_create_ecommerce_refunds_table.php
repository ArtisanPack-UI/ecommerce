<?php

/**
 * Creates the `ecommerce_refunds` table (engine spec §3.21).
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
        Schema::create( 'ecommerce_refunds', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'order_id' );
            $table->bigInteger( 'amount' );
            $table->char( 'currency', 3 );
            $table->string( 'reason', 255 )->nullable();
            $table->string( 'gateway_reference', 255 )->nullable();
            $table->unsignedBigInteger( 'issued_by_user_id' )->nullable();
            $table->timestamps();

            $table->index( 'order_id', 'ecommerce_refunds_order_idx' );

            $table->foreign( 'order_id', 'ecommerce_refunds_order_fk' )
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
        Schema::dropIfExists( 'ecommerce_refunds' );
    }
};
