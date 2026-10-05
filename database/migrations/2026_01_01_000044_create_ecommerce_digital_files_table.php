<?php

/**
 * Creates the `ecommerce_digital_files` table.
 *
 * A deliverable file attached to a product or variant (engine spec §3.27,
 * parent plan §5.13). Files live on a filesystem disk (`disk` + `path`) or
 * in the media library (`media_id`); `is_streaming_only` files are never
 * served through the download endpoint.
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
        Schema::create( 'ecommerce_digital_files', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'product_id' )->nullable();
            $table->unsignedBigInteger( 'product_variant_id' )->nullable();
            $table->unsignedBigInteger( 'media_id' )->nullable();
            $table->string( 'disk', 60 )->nullable();
            $table->string( 'path', 1000 )->nullable();
            $table->string( 'label', 255 );
            $table->string( 'version', 60 )->nullable();
            $table->boolean( 'is_streaming_only' )->default( false );
            $table->char( 'checksum_sha256', 64 )->nullable();
            // Retired: issues no new entitlements; existing ones keep working.
            $table->timestamp( 'archived_at' )->nullable();
            $table->timestamps();

            $table->index( 'product_id', 'ecommerce_digital_files_product_idx' );
            $table->index( 'product_variant_id', 'ecommerce_digital_files_variant_idx' );

            $table->foreign( 'product_id', 'ecommerce_digital_files_product_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_products' )
                ->nullOnDelete();

            $table->foreign( 'product_variant_id', 'ecommerce_digital_files_variant_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_product_variants' )
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
        Schema::dropIfExists( 'ecommerce_digital_files' );
    }
};
