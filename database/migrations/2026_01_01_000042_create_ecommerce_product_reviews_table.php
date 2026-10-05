<?php

/**
 * Creates the `ecommerce_product_reviews` table.
 *
 * A customer's rating + review of a product (engine spec §3.26, parent
 * plan §5.12). Reviews enter a moderation queue (`pending`) unless a
 * `ReviewModerator` decides otherwise; only `approved` rows count toward
 * the denormalized `products.avg_rating` / `products.reviews_count`.
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
        Schema::create( 'ecommerce_product_reviews', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'product_id' );
            $table->unsignedBigInteger( 'customer_id' )->nullable();
            $table->unsignedBigInteger( 'order_id' )->nullable();
            $table->string( 'author_name', 255 );
            $table->string( 'author_email', 255 )->nullable();
            $table->unsignedTinyInteger( 'rating' );
            $table->string( 'title', 255 )->nullable();
            $table->text( 'body' )->nullable();
            $table->boolean( 'is_verified_purchase' )->default( false );
            $table->string( 'status', 20 )->default( 'pending' );
            $table->timestamp( 'approved_at' )->nullable();
            $table->unsignedBigInteger( 'reviewed_by_user_id' )->nullable();
            $table->timestamps();

            $table->index( [ 'product_id', 'status' ], 'ecommerce_product_reviews_product_status_idx' );
            $table->index( 'customer_id', 'ecommerce_product_reviews_customer_idx' );

            $table->foreign( 'product_id', 'ecommerce_product_reviews_product_fk' )
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
        Schema::dropIfExists( 'ecommerce_product_reviews' );
    }
};
