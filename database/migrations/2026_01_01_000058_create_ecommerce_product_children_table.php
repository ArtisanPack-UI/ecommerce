<?php

/**
 * Creates the `ecommerce_product_children` table (engine spec §3.10a).
 *
 * The products a `grouped` or `bundled` product is made of, in order, with
 * a quantity per child. A child may pin one variant of a variable product.
 * Deleting the parent removes its rows; deleting a child product removes
 * the link, so a bundle never points at a missing product.
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
        Schema::create( 'ecommerce_product_children', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'parent_product_id' );
            $table->unsignedBigInteger( 'child_product_id' );
            $table->unsignedBigInteger( 'child_variant_id' )->nullable();
            $table->unsignedInteger( 'quantity' )->default( 1 );
            $table->unsignedInteger( 'position' )->default( 0 );
            $table->timestamps();

            $table->index( 'parent_product_id', 'ecommerce_product_children_parent_idx' );
            $table->index( 'child_product_id', 'ecommerce_product_children_child_idx' );
            $table->index( 'child_variant_id', 'ecommerce_product_children_child_variant_idx' );

            $table->foreign( 'parent_product_id', 'ecommerce_product_children_parent_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_products' )
                ->cascadeOnDelete();
            $table->foreign( 'child_product_id', 'ecommerce_product_children_child_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_products' )
                ->cascadeOnDelete();
            $table->foreign( 'child_variant_id', 'ecommerce_product_children_variant_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_product_variants' )
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
        Schema::dropIfExists( 'ecommerce_product_children' );
    }
};
