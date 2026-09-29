<?php

/**
 * Creates the `license_activations` table.
 *
 * One machine a license key is activated on (engine spec §3.27), keyed by
 * the fingerprint the customer's app sends to `POST license/validate`.
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
        Schema::create( 'license_activations', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'license_key_id' );
            $table->string( 'machine_fingerprint', 128 )->nullable();
            $table->timestamp( 'activated_at' )->nullable();
            $table->timestamp( 'last_seen_at' )->nullable();
            $table->string( 'ip_address', 45 )->nullable();

            $table->index( 'license_key_id', 'license_activations_key_idx' );

            $table->foreign( 'license_key_id', 'license_activations_key_fk' )
                ->references( 'id' )
                ->on( 'license_keys' )
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
        Schema::dropIfExists( 'license_activations' );
    }
};
