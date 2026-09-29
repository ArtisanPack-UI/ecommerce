<?php

/**
 * Creates the `webhook_subscriptions` table.
 *
 * Outbound webhook endpoints registered by store operators / integrations
 * (engine spec §3.29). `secret` holds the encrypted HMAC signing key — the
 * column is TEXT rather than the spec's VARCHAR(255) because Laravel's
 * encrypted payload for a 64-char secret exceeds 255 bytes.
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
        Schema::create( 'webhook_subscriptions', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'name', 255 );
            $table->string( 'url', 1000 );
            $table->text( 'secret' );
            $table->json( 'events' );
            $table->boolean( 'is_active' )->default( true );
            $table->timestamp( 'last_success_at' )->nullable();
            $table->timestamp( 'last_failure_at' )->nullable();
            $table->unsignedInteger( 'consecutive_failures' )->default( 0 );
            $table->timestamps();

            $table->index( 'is_active', 'webhook_subscriptions_active_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'webhook_subscriptions' );
    }
};
