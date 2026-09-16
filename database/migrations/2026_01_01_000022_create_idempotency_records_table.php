<?php

/**
 * Creates the `idempotency_records` table (engine spec §3.31).
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
        Schema::create( 'idempotency_records', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'actor_scope', 191 );
            $table->string( 'endpoint_key', 191 );
            $table->string( 'idempotency_key', 255 );
            $table->char( 'request_hash', 64 );
            $table->unsignedSmallInteger( 'response_status' )->nullable();
            $table->json( 'response_headers' )->nullable();
            $table->longText( 'response_body' )->nullable();
            $table->timestamp( 'locked_at' )->nullable();
            $table->timestamp( 'expires_at' );
            $table->timestamps();

            $table->unique(
                [ 'actor_scope', 'endpoint_key', 'idempotency_key' ],
                'idempotency_records_key_uk',
            );
            $table->index( 'expires_at', 'idempotency_records_expires_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'idempotency_records' );
    }
};
