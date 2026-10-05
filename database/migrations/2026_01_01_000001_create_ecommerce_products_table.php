<?php

/**
 * Creates the `ecommerce_products` table (engine spec §3.1).
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
        Schema::create( 'ecommerce_products', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'type', 60 );
            $table->string( 'name', 255 );
            $table->string( 'slug', 255 )->unique();
            $table->string( 'sku', 100 )->nullable()->unique();
            $table->string( 'barcode', 100 )->nullable();
            $table->longText( 'description' )->nullable();
            $table->text( 'short_description' )->nullable();
            $table->string( 'status', 20 )->default( 'draft' ); // draft|active|archived, validated by ProductService
            $table->unsignedBigInteger( 'featured_image_media_id' )->nullable();
            $table->boolean( 'is_taxable' )->default( true );
            $table->string( 'tax_class_key', 60 )->nullable();
            $table->decimal( 'weight', 8, 3 )->nullable();
            $table->string( 'weight_unit', 20 )->nullable(); // g|kg|oz|lb
            $table->decimal( 'length', 8, 3 )->nullable();
            $table->decimal( 'width', 8, 3 )->nullable();
            $table->decimal( 'height', 8, 3 )->nullable();
            $table->string( 'dim_unit', 20 )->nullable(); // mm|cm|in
            $table->decimal( 'avg_rating', 3, 2 )->default( 0 );
            $table->unsignedInteger( 'reviews_count' )->default( 0 );
            $table->boolean( 'is_featured' )->default( false );
            $table->unsignedInteger( 'position' )->default( 0 ); // manual catalog order
            $table->unsignedBigInteger( 'warehouse_id' )->nullable();
            $table->json( 'meta' )->nullable();
            $table->timestamp( 'published_at' )->nullable();
            $table->timestamps();

            $table->index( 'type', 'ecommerce_products_type_idx' );
            $table->index( 'status', 'ecommerce_products_status_idx' );
            $table->index( 'published_at', 'ecommerce_products_published_idx' );
            $table->index( [ 'status', 'is_featured' ], 'ecommerce_products_featured_idx' );
            $table->index( 'avg_rating', 'ecommerce_products_rating_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_products' );
    }
};
