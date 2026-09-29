<?php

/**
 * Creates the `product_review_media` table.
 *
 * Media attached to a product review (engine spec §3.26). `media_id` is
 * deliberately unconstrained so `artisanpack-ui/media-library` stays a soft
 * dependency.
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
        Schema::create( 'product_review_media', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'review_id' );
            $table->unsignedBigInteger( 'media_id' );

            $table->index( 'review_id', 'product_review_media_review_idx' );

            $table->foreign( 'review_id', 'product_review_media_review_fk' )
                ->references( 'id' )
                ->on( 'product_reviews' )
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
        Schema::dropIfExists( 'product_review_media' );
    }
};
