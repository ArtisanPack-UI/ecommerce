<?php

/**
 * Creates the `digital_downloads` table.
 *
 * A download entitlement for one order line + file (engine spec §3.27).
 * `token` holds the sha256 of the opaque server-issued token — the plain
 * token only ever exists in the link sent to the customer.
 * `downloads_remaining` (null = unlimited) is decremented atomically under
 * a row lock by the download endpoint.
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
        Schema::create( 'digital_downloads', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'order_item_id' );
            $table->unsignedBigInteger( 'digital_file_id' );
            $table->char( 'token', 64 );
            $table->unsignedInteger( 'downloads_remaining' )->nullable();
            $table->timestamp( 'expires_at' )->nullable();
            $table->timestamp( 'first_downloaded_at' )->nullable();
            $table->timestamp( 'last_downloaded_at' )->nullable();
            $table->unsignedInteger( 'download_count' )->default( 0 );
            $table->timestamps();

            $table->unique( 'token', 'digital_downloads_token_uk' );
            $table->index( 'order_item_id', 'digital_downloads_order_item_idx' );

            $table->foreign( 'order_item_id', 'digital_downloads_order_item_fk' )
                ->references( 'id' )
                ->on( 'order_items' )
                ->cascadeOnDelete();

            $table->foreign( 'digital_file_id', 'digital_downloads_file_fk' )
                ->references( 'id' )
                ->on( 'digital_files' )
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
        Schema::dropIfExists( 'digital_downloads' );
    }
};
