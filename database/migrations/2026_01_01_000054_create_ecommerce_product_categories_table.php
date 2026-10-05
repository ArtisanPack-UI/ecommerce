<?php

/**
 * Creates the `ecommerce_product_categories` table (engine spec §3.7).
 *
 * A category tree: `parent_id` points at another category and is set to
 * null when the parent is deleted, so children move up a level.
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
        Schema::create( 'ecommerce_product_categories', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'parent_id' )->nullable();
            $table->string( 'name', 255 );
            $table->string( 'slug', 255 )->unique( 'ecommerce_product_categories_slug_uk' );
            $table->text( 'description' )->nullable();
            $table->unsignedBigInteger( 'image_media_id' )->nullable();
            $table->string( 'icon', 80 )->nullable();
            $table->unsignedInteger( 'position' )->default( 0 );
            $table->timestamps();

            $table->index( 'parent_id', 'ecommerce_product_categories_parent_idx' );

            $table->foreign( 'parent_id', 'ecommerce_product_categories_parent_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_product_categories' )
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
        Schema::dropIfExists( 'ecommerce_product_categories' );
    }
};
