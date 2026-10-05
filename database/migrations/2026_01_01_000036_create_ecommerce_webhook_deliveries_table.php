<?php

/**
 * Creates the `ecommerce_webhook_deliveries` table.
 *
 * Retry ledger for outbound webhook deliveries (engine spec §3.29 / §8.2).
 * One row per (subscription, event occurrence); `attempts` and
 * `next_retry_at` drive the exponential backoff, `delivered_at` marks
 * success. A row with neither `delivered_at` nor `next_retry_at` has
 * exhausted its attempts.
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
        Schema::create( 'ecommerce_webhook_deliveries', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'subscription_id' );
            $table->string( 'event', 120 );
            // The order and customer the delivery is about, copied from the
            // payload when it is queued (WebhookDispatcher::subjectIds()), so
            // customer delete-and-anonymize finds the deliveries to redact
            // through an index. No foreign keys: history outlives the rows.
            $table->unsignedBigInteger( 'order_id' )->nullable();
            $table->unsignedBigInteger( 'customer_id' )->nullable();
            $table->char( 'payload_hash', 64 );
            $table->json( 'payload' );
            $table->unsignedSmallInteger( 'response_status' )->nullable();
            $table->text( 'response_body' )->nullable();
            $table->unsignedInteger( 'attempts' )->default( 0 );
            $table->timestamp( 'delivered_at' )->nullable();
            $table->timestamp( 'next_retry_at' )->nullable();
            $table->timestamp( 'created_at' )->nullable();

            $table->index( 'subscription_id', 'ecommerce_webhook_deliveries_subscription_idx' );
            $table->index( 'next_retry_at', 'ecommerce_webhook_deliveries_retry_idx' );
            $table->index( 'order_id', 'ecommerce_webhook_deliveries_order_idx' );
            $table->index( 'customer_id', 'ecommerce_webhook_deliveries_customer_idx' );
            $table->index( 'created_at', 'ecommerce_webhook_deliveries_created_idx' );
            $table->foreign( 'subscription_id', 'ecommerce_webhook_deliveries_subscription_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_webhook_subscriptions' )
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
        Schema::dropIfExists( 'ecommerce_webhook_deliveries' );
    }
};
