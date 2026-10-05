<?php

/**
 * Creates the `ecommerce_customers` table (engine spec §3.22).
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
        Schema::create( 'ecommerce_customers', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'user_id' )->nullable();
            $table->string( 'email', 255 );
            $table->string( 'first_name', 120 )->nullable();
            $table->string( 'last_name', 120 )->nullable();
            $table->string( 'phone', 50 )->nullable();
            // Preferred language for notifications (H1).
            $table->string( 'locale', 12 )->nullable();
            $table->boolean( 'accepts_marketing' )->default( false );
            $table->timestamp( 'accepts_marketing_at' )->nullable();
            $table->bigInteger( 'total_spent_amount' )->default( 0 );
            $table->char( 'total_spent_currency', 3 )->nullable();
            $table->unsignedInteger( 'orders_count' )->default( 0 );
            $table->timestamp( 'last_ordered_at' )->nullable();
            $table->json( 'meta' )->nullable();
            $table->timestamps();

            // One customer per (lowercased) email and per linked user. A
            // unique index allows any number of NULL user_ids (guests).
            $table->unique( 'email', 'ecommerce_customers_email_uk' );
            $table->unique( 'user_id', 'ecommerce_customers_user_uk' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_customers' );
    }
};
