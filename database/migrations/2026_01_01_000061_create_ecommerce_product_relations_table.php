<?php

/**
 * Creates the `ecommerce_product_relations` table.
 *
 * Hand-picked links between products (#182): `upsell` (shown on the
 * product page instead of it), `cross_sell` (suggested in the cart), and
 * `related` ("you may also like"), each ordered by `position`.
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
        Schema::create( 'ecommerce_product_relations', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'product_id' );
            $table->unsignedBigInteger( 'related_product_id' );
            $table->string( 'type', 20 );
            $table->unsignedInteger( 'position' )->default( 0 );
            $table->timestamps();

            $table->unique( [ 'product_id', 'type', 'related_product_id' ], 'ecommerce_product_relations_uk' );
            $table->index( [ 'product_id', 'type', 'position' ], 'ecommerce_product_relations_product_type_idx' );
            $table->index( 'related_product_id', 'ecommerce_product_relations_related_idx' );

            $table->foreign( 'product_id', 'ecommerce_product_relations_product_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_products' )
                ->cascadeOnDelete();
            $table->foreign( 'related_product_id', 'ecommerce_product_relations_related_fk' )
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
        Schema::dropIfExists( 'ecommerce_product_relations' );
    }
};
