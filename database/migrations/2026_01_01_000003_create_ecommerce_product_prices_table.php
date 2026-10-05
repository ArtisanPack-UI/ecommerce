<?php

/**
 * Creates the `ecommerce_product_prices` table (engine spec §3.2).
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
        Schema::create( 'ecommerce_product_prices', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'priceable_type', 191 );
            $table->unsignedBigInteger( 'priceable_id' );
            $table->char( 'currency', 3 );
            $table->bigInteger( 'price_amount' );
            $table->bigInteger( 'compare_at_amount' )->nullable();
            $table->bigInteger( 'cost_amount' )->nullable();
            $table->timestamp( 'starts_at' )->nullable();
            $table->timestamp( 'ends_at' )->nullable();
            // `{currency}|{starts unix}|{ends unix}`, written by ProductPrice on
            // save. The unique index can't use the nullable window columns
            // themselves (NULLs are distinct), so it uses this instead.
            $table->string( 'window_key', 40 );
            $table->timestamps();

            $table->unique(
                [ 'priceable_type', 'priceable_id', 'window_key' ],
                'ecommerce_product_prices_window_uk',
            );
            $table->index( [ 'priceable_type', 'priceable_id' ], 'ecommerce_product_prices_priceable_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_product_prices' );
    }
};
