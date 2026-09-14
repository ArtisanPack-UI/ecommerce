<?php

/**
 * Creates the `product_variants` table (engine spec §3.3).
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
        Schema::create( 'product_variants', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->foreignId( 'product_id' )
                ->constrained( 'products' )
                ->cascadeOnDelete();
            $table->string( 'sku', 100 )->nullable()->unique();
            $table->string( 'barcode', 100 )->nullable();
            $table->string( 'name', 255 )->nullable();
            $table->unsignedBigInteger( 'image_media_id' )->nullable();
            $table->decimal( 'weight', 8, 3 )->nullable();
            $table->enum( 'weight_unit', [ 'g', 'kg', 'oz', 'lb' ] )->nullable();
            $table->decimal( 'length', 8, 3 )->nullable();
            $table->decimal( 'width', 8, 3 )->nullable();
            $table->decimal( 'height', 8, 3 )->nullable();
            $table->enum( 'dim_unit', [ 'mm', 'cm', 'in' ] )->nullable();
            $table->unsignedInteger( 'position' )->default( 0 );
            $table->json( 'meta' )->nullable();
            $table->timestamps();
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'product_variants' );
    }
};
