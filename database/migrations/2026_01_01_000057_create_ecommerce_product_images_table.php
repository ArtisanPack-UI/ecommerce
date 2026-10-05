<?php

/**
 * Creates the `ecommerce_product_images` table (engine spec §3.10).
 *
 * The ordered gallery, independent of `products.featured_image_media_id`.
 * A row points at a media-library item (`media_id`) when that package is
 * installed, or at a plain URL (`image_url`) when it is not.
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
        Schema::create( 'ecommerce_product_images', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'product_id' );
            $table->unsignedBigInteger( 'media_id' )->nullable();
            $table->string( 'image_url', 1000 )->nullable();
            $table->string( 'alt_text', 255 )->nullable();
            $table->unsignedInteger( 'position' )->default( 0 );
            $table->timestamps();

            $table->index( 'product_id', 'ecommerce_product_images_product_idx' );

            $table->foreign( 'product_id', 'ecommerce_product_images_product_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_products' )
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
        Schema::dropIfExists( 'ecommerce_product_images' );
    }
};
