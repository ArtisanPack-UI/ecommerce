<?php

/**
 * Creates the `ecommerce_orders` table (engine spec §3.15).
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
        Schema::create( 'ecommerce_orders', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'order_number', 50 );
            $table->unsignedBigInteger( 'customer_id' )->nullable();
            $table->string( 'email', 255 );
            $table->string( 'phone', 50 )->nullable();
            $table->string( 'system_status', 60 );
            $table->unsignedBigInteger( 'substatus_id' )->nullable();
            $table->string( 'payment_status', 60 );
            $table->string( 'fulfillment_status', 60 );
            $table->char( 'currency', 3 );
            // The shopper's locale when they ordered (notifications, H1).
            $table->string( 'locale', 12 )->nullable();
            $table->char( 'base_currency', 3 );
            $table->bigInteger( 'fx_rate_to_base_e8' );
            $table->bigInteger( 'subtotal_amount' );
            $table->char( 'subtotal_currency', 3 );
            $table->bigInteger( 'discount_amount' )->default( 0 );
            $table->char( 'discount_currency', 3 );
            $table->bigInteger( 'tax_amount' )->default( 0 );
            $table->char( 'tax_currency', 3 );
            $table->bigInteger( 'shipping_amount' )->default( 0 );
            $table->char( 'shipping_currency', 3 );
            $table->bigInteger( 'total_amount' );
            $table->char( 'total_currency', 3 );
            $table->bigInteger( 'total_refunded_amount' )->default( 0 );
            $table->char( 'total_refunded_currency', 3 );
            $table->json( 'shipping_address' )->nullable();
            $table->json( 'billing_address' )->nullable();
            $table->string( 'shipping_method_key', 120 )->nullable();
            $table->string( 'payment_gateway_key', 120 )->nullable();
            $table->string( 'payment_reference', 255 )->nullable();
            $table->string( 'ip_address', 45 )->nullable();
            $table->text( 'user_agent' )->nullable();
            $table->text( 'customer_note' )->nullable();
            $table->boolean( 'is_claimed' )->default( true );
            $table->json( 'meta' )->nullable();
            $table->timestamp( 'placed_at' )->nullable();
            $table->timestamps();

            $table->unique( 'order_number', 'ecommerce_orders_order_number_uk' );
            $table->index( 'customer_id', 'ecommerce_orders_customer_idx' );
            $table->index( 'email', 'ecommerce_orders_email_idx' );
            $table->index( 'system_status', 'ecommerce_orders_system_status_idx' );
            $table->index( 'payment_status', 'ecommerce_orders_payment_status_idx' );
            $table->index( 'fulfillment_status', 'ecommerce_orders_fulfillment_status_idx' );
            $table->index( 'placed_at', 'ecommerce_orders_placed_at_idx' );
            $table->index( 'substatus_id', 'ecommerce_orders_substatus_idx' );

            $table->foreign( 'customer_id', 'ecommerce_orders_customer_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_customers' )
                ->nullOnDelete();

            $table->foreign( 'substatus_id', 'ecommerce_orders_substatus_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_order_substatuses' )
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
        Schema::dropIfExists( 'ecommerce_orders' );
    }
};
