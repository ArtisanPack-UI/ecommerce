<?php

/**
 * Creates the `cart_items` table (engine spec §3.14).
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
        Schema::create( 'cart_items', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'cart_id' );
            $table->unsignedBigInteger( 'product_id' );
            $table->unsignedBigInteger( 'product_variant_id' )->nullable();
            $table->unsignedInteger( 'quantity' );
            $table->bigInteger( 'unit_price_amount' );
            $table->char( 'unit_price_currency', 3 );
            $table->bigInteger( 'line_subtotal_amount' );
            $table->char( 'line_subtotal_currency', 3 );
            $table->bigInteger( 'line_total_amount' );
            $table->char( 'line_total_currency', 3 );
            $table->json( 'options' )->nullable();
            $table->json( 'meta' )->nullable();
            $table->char( 'options_hash', 64 );
            $table->timestamps();

            $table->index( 'cart_id', 'cart_items_cart_idx' );
            $table->index(
                [ 'cart_id', 'product_id', 'product_variant_id', 'options_hash' ],
                'cart_items_dedupe_idx',
            );

            $table->foreign( 'cart_id', 'cart_items_cart_fk' )
                ->references( 'id' )
                ->on( 'carts' )
                ->cascadeOnDelete();

            $table->foreign( 'product_id', 'cart_items_product_fk' )
                ->references( 'id' )
                ->on( 'products' )
                ->restrictOnDelete();

            $table->foreign( 'product_variant_id', 'cart_items_variant_fk' )
                ->references( 'id' )
                ->on( 'product_variants' )
                ->restrictOnDelete();
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'cart_items' );
    }
};
