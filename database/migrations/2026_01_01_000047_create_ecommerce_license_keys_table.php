<?php

/**
 * Creates the `ecommerce_license_keys` table.
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
        Schema::create( 'ecommerce_license_keys', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'order_item_id' );
            $table->unsignedBigInteger( 'digital_file_id' )->nullable();
            // The key itself, encrypted (shown to admins and the owner).
            $table->text( 'key' );
            // HMAC-SHA256 of the normalized key under the app key: the lookup
            // column, so plaintext keys never sit in the table.
            $table->char( 'key_hash', 64 );
            $table->unsignedInteger( 'activations_limit' )->nullable();
            $table->unsignedInteger( 'activations_count' )->default( 0 );
            $table->timestamp( 'expires_at' )->nullable();
            $table->boolean( 'is_revoked' )->default( false );
            $table->timestamp( 'revoked_at' )->nullable();
            $table->json( 'meta' );
            $table->timestamps();

            $table->unique( 'key_hash', 'ecommerce_license_keys_key_hash_uk' );
            $table->index( 'order_item_id', 'ecommerce_license_keys_order_item_idx' );
            $table->index( 'digital_file_id', 'ecommerce_license_keys_digital_file_idx' );

            $table->foreign( 'order_item_id', 'ecommerce_license_keys_order_item_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_order_items' )
                ->restrictOnDelete();

            $table->foreign( 'digital_file_id', 'ecommerce_license_keys_file_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_digital_files' )
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
        Schema::dropIfExists( 'ecommerce_license_keys' );
    }
};
