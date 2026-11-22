<?php

/**
 * Creates the `ecommerce_cart_payment_sessions` table.
 *
 * Every provider payment session a cart was given, so a session the cart
 * no longer points at (its total changed, a new session replaced it, or the
 * gateway changed) can still be matched when its provider reports it
 * confirmed. `ecommerce_carts.payment_reference` stays the cart's current
 * session. A row records how the session ended: paid for an order, held
 * for review, refunded, voided, or cancelled.
 *
 * Rows outlive their cart (`cart_id` becomes null when it is pruned).
 * Only provider references, amounts and statuses are stored, never card
 * data.
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
        Schema::create( 'ecommerce_cart_payment_sessions', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            // Null once the cart is pruned: the row is kept so a late
            // provider confirmation can still be matched.
            $table->unsignedBigInteger( 'cart_id' )->nullable();
            $table->string( 'gateway_key', 120 );
            $table->string( 'reference', 255 );
            // Null for a session recorded from a pre-1.4 cart reference,
            // until the provider is asked about it.
            $table->bigInteger( 'amount' )->nullable();
            $table->char( 'currency', 3 )->nullable();
            // CartPaymentSession::STATUS_* (the engine's view of the session).
            $table->string( 'status', 30 )->default( 'active' );
            // The provider's last reported PaymentSession::STATUS_* value.
            $table->string( 'provider_status', 40 )->nullable();
            // Why the session was stranded (StrandedPaymentService::REASON_*).
            $table->string( 'reason', 40 )->nullable();
            $table->unsignedBigInteger( 'order_id' )->nullable();
            // The provider's refund id when a stranded session was refunded.
            $table->string( 'resolution_reference', 255 )->nullable();
            $table->timestamp( 'superseded_at' )->nullable();
            $table->timestamp( 'stranded_at' )->nullable();
            $table->timestamp( 'resolved_at' )->nullable();
            // Set when a refund or void was sent to the provider; an unknown
            // outcome is retried with the same request, never held as paid.
            $table->timestamp( 'release_attempted_at' )->nullable();
            $table->timestamps();

            $table->unique( [ 'gateway_key', 'reference' ], 'ecommerce_cart_payment_sessions_reference_uk' );
            $table->index( 'reference', 'ecommerce_cart_payment_sessions_reference_idx' );
            $table->index( [ 'cart_id', 'status' ], 'ecommerce_cart_payment_sessions_cart_idx' );
            $table->index( [ 'status', 'updated_at' ], 'ecommerce_cart_payment_sessions_status_idx' );
            $table->index( 'order_id', 'ecommerce_cart_payment_sessions_order_idx' );

            $table->foreign( 'cart_id', 'ecommerce_cart_payment_sessions_cart_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_carts' )
                ->nullOnDelete();
            $table->foreign( 'order_id', 'ecommerce_cart_payment_sessions_order_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_orders' )
                ->nullOnDelete();
        } );
    }

    /**
     * @since 1.4.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_cart_payment_sessions' );
    }
};
