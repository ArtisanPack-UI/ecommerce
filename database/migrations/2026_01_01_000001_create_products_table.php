<?php

/**
 * Creates the `products` table (engine spec §3.1).
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
        Schema::create( 'products', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'type', 60 );
            $table->string( 'name', 255 );
            $table->string( 'slug', 255 )->unique();
            $table->string( 'sku', 100 )->nullable()->unique();
            $table->string( 'barcode', 100 )->nullable();
            $table->longText( 'description' )->nullable();
            $table->text( 'short_description' )->nullable();
            $table->enum( 'status', [ 'draft', 'active', 'archived' ] )->default( 'draft' );
            $table->unsignedBigInteger( 'featured_image_media_id' )->nullable();
            $table->boolean( 'is_taxable' )->default( true );
            $table->string( 'tax_class_key', 60 )->nullable();
            $table->decimal( 'weight', 8, 3 )->nullable();
            $table->enum( 'weight_unit', [ 'g', 'kg', 'oz', 'lb' ] )->nullable();
            $table->decimal( 'length', 8, 3 )->nullable();
            $table->decimal( 'width', 8, 3 )->nullable();
            $table->decimal( 'height', 8, 3 )->nullable();
            $table->enum( 'dim_unit', [ 'mm', 'cm', 'in' ] )->nullable();
            $table->decimal( 'avg_rating', 3, 2 )->default( 0 );
            $table->unsignedInteger( 'reviews_count' )->default( 0 );
            $table->unsignedBigInteger( 'warehouse_id' )->nullable();
            $table->json( 'meta' )->nullable();
            $table->timestamp( 'published_at' )->nullable();
            $table->timestamps();

            $table->index( 'type', 'products_type_idx' );
            $table->index( 'status', 'products_status_idx' );
            $table->index( 'published_at', 'products_published_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'products' );
    }
};
