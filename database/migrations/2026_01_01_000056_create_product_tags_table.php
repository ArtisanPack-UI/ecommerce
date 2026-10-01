<?php

/**
 * Creates the `product_tags` table and its `product_tag_product` pivot
 * (engine spec §3.9).
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
        Schema::create( 'product_tags', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'name', 120 );
            $table->string( 'slug', 120 )->unique( 'product_tags_slug_uk' );
            $table->timestamps();
        } );

        Schema::create( 'product_tag_product', function ( Blueprint $table ): void {
            $table->unsignedBigInteger( 'product_id' );
            $table->unsignedBigInteger( 'product_tag_id' );

            $table->primary( [ 'product_id', 'product_tag_id' ] );
            $table->index( 'product_tag_id', 'product_tag_product_tag_idx' );

            $table->foreign( 'product_id', 'product_tag_product_product_fk' )
                ->references( 'id' )
                ->on( 'products' )
                ->cascadeOnDelete();
            $table->foreign( 'product_tag_id', 'product_tag_product_tag_fk' )
                ->references( 'id' )
                ->on( 'product_tags' )
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
        Schema::dropIfExists( 'product_tag_product' );
        Schema::dropIfExists( 'product_tags' );
    }
};
