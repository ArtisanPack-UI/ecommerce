<?php

/**
 * Creates the `product_category_product` pivot (engine spec §3.8).
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
        Schema::create( 'product_category_product', function ( Blueprint $table ): void {
            $table->unsignedBigInteger( 'product_id' );
            $table->unsignedBigInteger( 'product_category_id' );

            $table->primary( [ 'product_id', 'product_category_id' ] );
            $table->index( 'product_category_id', 'product_category_product_cat_idx' );

            $table->foreign( 'product_id', 'product_category_product_product_fk' )
                ->references( 'id' )
                ->on( 'products' )
                ->cascadeOnDelete();
            $table->foreign( 'product_category_id', 'product_category_product_cat_fk' )
                ->references( 'id' )
                ->on( 'product_categories' )
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
        Schema::dropIfExists( 'product_category_product' );
    }
};
