<?php

/**
 * Creates the `customers` table (engine spec §3.22).
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
        Schema::create( 'customers', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'user_id' )->nullable();
            $table->string( 'email', 255 );
            $table->string( 'first_name', 120 )->nullable();
            $table->string( 'last_name', 120 )->nullable();
            $table->string( 'phone', 50 )->nullable();
            $table->boolean( 'accepts_marketing' )->default( false );
            $table->timestamp( 'accepts_marketing_at' )->nullable();
            $table->bigInteger( 'total_spent_amount' )->default( 0 );
            $table->char( 'total_spent_currency', 3 )->nullable();
            $table->unsignedInteger( 'orders_count' )->default( 0 );
            $table->timestamp( 'last_ordered_at' )->nullable();
            $table->json( 'meta' )->nullable();
            $table->timestamps();

            $table->index( 'email', 'customers_email_idx' );
            $table->index( 'user_id', 'customers_user_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'customers' );
    }
};
