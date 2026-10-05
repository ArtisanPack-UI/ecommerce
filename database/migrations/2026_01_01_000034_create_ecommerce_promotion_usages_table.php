<?php

/**
 * Creates the `ecommerce_promotion_usages` table (engine spec §3.23).
 *
 * Adds a `(promotion_id, order_id)` unique key beyond the spec so usage is
 * recorded at most once per order, and a `(promotion_id, customer_id)`
 * index for the per-customer limit check.
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
        Schema::create( 'ecommerce_promotion_usages', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'promotion_id' );
            $table->unsignedBigInteger( 'order_id' );
            $table->unsignedBigInteger( 'customer_id' )->nullable();
            $table->bigInteger( 'amount_discounted' );
            $table->char( 'currency', 3 );
            $table->timestamp( 'created_at' )->nullable();

            $table->index( 'customer_id', 'ecommerce_promotion_usages_customer_idx' );
            $table->index( 'promotion_id', 'ecommerce_promotion_usages_promotion_idx' );
            $table->index( [ 'promotion_id', 'customer_id' ], 'ecommerce_promotion_usages_promotion_customer_idx' );
            $table->unique( [ 'promotion_id', 'order_id' ], 'ecommerce_promotion_usages_promotion_order_uk' );

            $table->foreign( 'promotion_id', 'ecommerce_promotion_usages_promotion_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_promotions' )
                ->cascadeOnDelete();

            $table->foreign( 'order_id', 'ecommerce_promotion_usages_order_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_orders' )
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
        Schema::dropIfExists( 'ecommerce_promotion_usages' );
    }
};
