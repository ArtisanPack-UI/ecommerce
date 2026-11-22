<?php

/**
 * Creates the `ecommerce_back_in_stock_subscriptions` table.
 *
 * Shoppers who asked to hear when an out-of-stock product (or one of its
 * variants) is available again. A row is pending until it is notified
 * (`notified_at`) or unsubscribed (`unsubscribed_at`); each row is
 * notified once. Personal data: removed with the customer on erasure.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * @since 1.4.0
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create( 'ecommerce_back_in_stock_subscriptions', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'email', 254 );
            $table->unsignedBigInteger( 'customer_id' )->nullable();
            $table->unsignedBigInteger( 'product_id' );
            $table->unsignedBigInteger( 'product_variant_id' )->nullable();
            $table->string( 'locale', 20 )->nullable();
            $table->timestamp( 'notified_at' )->nullable();
            $table->timestamp( 'unsubscribed_at' )->nullable();
            $table->timestamps();

            $table->index( [ 'product_id', 'product_variant_id', 'notified_at', 'unsubscribed_at' ], 'ecommerce_bis_subscriptions_pending_idx' );
            $table->index( 'email', 'ecommerce_bis_subscriptions_email_idx' );
            $table->index( 'customer_id', 'ecommerce_bis_subscriptions_customer_idx' );
            $table->index( 'product_variant_id', 'ecommerce_bis_subscriptions_variant_idx' );

            $table->foreign( 'customer_id', 'ecommerce_bis_subscriptions_customer_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_customers' )
                ->nullOnDelete();
            $table->foreign( 'product_id', 'ecommerce_bis_subscriptions_product_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_products' )
                ->cascadeOnDelete();
            $table->foreign( 'product_variant_id', 'ecommerce_bis_subscriptions_variant_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_product_variants' )
                ->cascadeOnDelete();
        } );
    }

    /**
     * @since 1.4.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_back_in_stock_subscriptions' );
    }
};
