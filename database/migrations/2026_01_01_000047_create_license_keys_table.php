<?php

/**
 * Creates the `license_keys` table.
 *
 * A software license key issued for an order line (engine spec §3.27).
 * `activations_limit` (null = unlimited) caps the distinct machine
 * fingerprints recorded in `license_activations`.
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
        Schema::create( 'license_keys', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'order_item_id' );
            $table->unsignedBigInteger( 'digital_file_id' )->nullable();
            $table->string( 'key', 255 );
            $table->unsignedInteger( 'activations_limit' )->nullable();
            $table->unsignedInteger( 'activations_count' )->default( 0 );
            $table->timestamp( 'expires_at' )->nullable();
            $table->boolean( 'is_revoked' )->default( false );
            $table->timestamp( 'revoked_at' )->nullable();
            $table->json( 'meta' );
            $table->timestamps();

            $table->unique( 'key', 'license_keys_key_uk' );
            $table->index( 'order_item_id', 'license_keys_order_item_idx' );

            $table->foreign( 'order_item_id', 'license_keys_order_item_fk' )
                ->references( 'id' )
                ->on( 'order_items' )
                ->cascadeOnDelete();

            $table->foreign( 'digital_file_id', 'license_keys_file_fk' )
                ->references( 'id' )
                ->on( 'digital_files' )
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
        Schema::dropIfExists( 'license_keys' );
    }
};
