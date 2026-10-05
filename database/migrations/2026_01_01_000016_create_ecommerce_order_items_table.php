<?php

/**
 * Creates the `ecommerce_order_items` table (engine spec §3.16).
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
        Schema::create( 'ecommerce_order_items', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'order_id' );
            $table->unsignedBigInteger( 'product_id' )->nullable();
            $table->unsignedBigInteger( 'product_variant_id' )->nullable();
            $table->json( 'product_snapshot' );
            $table->unsignedInteger( 'quantity' );
            $table->bigInteger( 'unit_price_amount' );
            $table->char( 'unit_price_currency', 3 );
            $table->bigInteger( 'discount_amount' )->default( 0 );
            $table->char( 'discount_currency', 3 );
            $table->bigInteger( 'tax_amount' )->default( 0 );
            $table->char( 'tax_currency', 3 );
            $table->bigInteger( 'shipping_amount' )->default( 0 );
            $table->char( 'shipping_currency', 3 );
            $table->bigInteger( 'total_amount' );
            $table->char( 'total_currency', 3 );
            $table->string( 'fulfillment_status', 60 );
            $table->json( 'meta' )->nullable();
            $table->timestamps();

            $table->index( 'order_id', 'ecommerce_order_items_order_idx' );
            $table->index( 'product_id', 'ecommerce_order_items_product_idx' );
            $table->index( 'product_variant_id', 'ecommerce_order_items_product_variant_idx' );

            $table->foreign( 'order_id', 'ecommerce_order_items_order_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_orders' )
                ->cascadeOnDelete();

            $table->foreign( 'product_id', 'ecommerce_order_items_product_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_products' )
                ->nullOnDelete();

            $table->foreign( 'product_variant_id', 'ecommerce_order_items_variant_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_product_variants' )
                ->nullOnDelete();
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_order_items' );
    }
};
