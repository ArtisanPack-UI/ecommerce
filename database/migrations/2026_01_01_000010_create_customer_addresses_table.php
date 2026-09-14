<?php

/**
 * Creates the `customer_addresses` table (engine spec §3.22).
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
        Schema::create( 'customer_addresses', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'customer_id' );
            $table->string( 'label', 120 )->nullable();
            $table->boolean( 'is_default_shipping' )->default( false );
            $table->boolean( 'is_default_billing' )->default( false );
            $table->string( 'first_name', 120 )->nullable();
            $table->string( 'last_name', 120 )->nullable();
            $table->string( 'company', 120 )->nullable();
            $table->string( 'phone', 50 )->nullable();
            $table->string( 'address1', 255 )->nullable();
            $table->string( 'address2', 255 )->nullable();
            $table->string( 'city', 120 )->nullable();
            $table->string( 'region', 120 )->nullable();
            $table->string( 'region_code', 10 )->nullable();
            $table->string( 'postal_code', 20 )->nullable();
            $table->char( 'country_code', 2 )->nullable();
            $table->timestamps();

            $table->index( 'customer_id', 'customer_addresses_customer_idx' );

            $table->foreign( 'customer_id', 'customer_addresses_customer_fk' )
                ->references( 'id' )
                ->on( 'customers' )
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
        Schema::dropIfExists( 'customer_addresses' );
    }
};
