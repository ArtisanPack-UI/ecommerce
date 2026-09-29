<?php

/**
 * Creates the `digital_download_events` table.
 *
 * Audit trail of download-endpoint hits (engine spec §3.27): successful
 * `download` / `stream` requests and `forbidden` attempts (expired or
 * exhausted tokens).
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
        Schema::create( 'digital_download_events', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'digital_download_id' );
            $table->string( 'ip_address', 45 )->nullable();
            $table->text( 'user_agent' )->nullable();
            $table->string( 'event_type', 30 );
            $table->timestamp( 'created_at' )->nullable();

            $table->index( 'digital_download_id', 'digital_download_events_download_idx' );

            $table->foreign( 'digital_download_id', 'digital_download_events_download_fk' )
                ->references( 'id' )
                ->on( 'digital_downloads' )
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
        Schema::dropIfExists( 'digital_download_events' );
    }
};
