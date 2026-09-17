<?php

/**
 * Creates the `inbound_webhook_deliveries` table.
 *
 * Audit / replay ledger for every inbound provider webhook the engine
 * receives, verified or not. Idempotent dispatch is still governed by
 * `idempotency_records` (engine spec §3.31); this table exists so
 * operators can inspect what came in, replay a specific delivery, and
 * see rejected signatures in one place.
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
        Schema::create( 'inbound_webhook_deliveries', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'provider', 60 );
            $table->string( 'event_id', 255 )->nullable();
            $table->string( 'event_type', 191 )->nullable();
            $table->boolean( 'verified' );
            $table->boolean( 'duplicate' )->default( false );
            $table->string( 'error_code', 60 )->nullable();
            $table->char( 'payload_hash', 64 );
            $table->longText( 'payload' );
            $table->json( 'parsed' )->nullable();
            $table->unsignedSmallInteger( 'response_status' );
            $table->string( 'correlation_id', 64 )->nullable();
            $table->timestamp( 'received_at' );
            $table->timestamps();

            $table->index( [ 'provider', 'event_id' ], 'inbound_webhook_deliveries_provider_event_idx' );
            $table->index( 'received_at', 'inbound_webhook_deliveries_received_idx' );
            $table->index( 'correlation_id', 'inbound_webhook_deliveries_correlation_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'inbound_webhook_deliveries' );
    }
};
