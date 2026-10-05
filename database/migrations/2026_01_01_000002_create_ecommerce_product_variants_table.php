<?php

/**
 * Creates the `ecommerce_product_variants` table (engine spec §3.3).
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
        Schema::create( 'ecommerce_product_variants', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->foreignId( 'product_id' )
                ->constrained( 'ecommerce_products' )
                ->cascadeOnDelete();
            $table->string( 'sku', 100 )->nullable()->unique();
            $table->string( 'barcode', 100 )->nullable();
            $table->string( 'name', 255 )->nullable();
            $table->unsignedBigInteger( 'image_media_id' )->nullable();
            $table->decimal( 'weight', 8, 3 )->nullable();
            $table->string( 'weight_unit', 20 )->nullable(); // g|kg|oz|lb
            $table->decimal( 'length', 8, 3 )->nullable();
            $table->decimal( 'width', 8, 3 )->nullable();
            $table->decimal( 'height', 8, 3 )->nullable();
            $table->string( 'dim_unit', 20 )->nullable(); // mm|cm|in
            $table->unsignedInteger( 'position' )->default( 0 );
            $table->json( 'meta' )->nullable();
            $table->timestamps();

            $table->index( 'product_id', 'ecommerce_product_variants_product_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_product_variants' );
    }
};
