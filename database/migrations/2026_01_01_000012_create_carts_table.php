<?php

/**
 * Creates the `carts` table (engine spec §3.13).
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
        Schema::create( 'carts', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->char( 'token', 40 );
            $table->unsignedBigInteger( 'customer_id' )->nullable();
            $table->char( 'currency', 3 );
            $table->string( 'email', 255 )->nullable();
            $table->bigInteger( 'subtotal_amount' )->default( 0 );
            $table->char( 'subtotal_currency', 3 );
            $table->bigInteger( 'discount_amount' )->default( 0 );
            $table->char( 'discount_currency', 3 );
            $table->bigInteger( 'tax_amount' )->default( 0 );
            $table->char( 'tax_currency', 3 );
            $table->bigInteger( 'shipping_amount' )->default( 0 );
            $table->char( 'shipping_currency', 3 );
            $table->bigInteger( 'total_amount' )->default( 0 );
            $table->char( 'total_currency', 3 );
            $table->timestamp( 'checkout_started_at' )->nullable();
            $table->timestamp( 'abandoned_at' )->nullable();
            $table->unsignedBigInteger( 'completed_order_id' )->nullable();
            $table->json( 'meta' )->nullable();
            $table->timestamp( 'expires_at' )->nullable();
            $table->timestamps();

            $table->unique( 'token', 'carts_token_uk' );
            $table->index( 'customer_id', 'carts_customer_idx' );
            $table->index( 'abandoned_at', 'carts_abandoned_idx' );
            $table->index( 'expires_at', 'carts_expires_idx' );
            $table->index( 'completed_order_id', 'carts_completed_order_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'carts' );
    }
};
