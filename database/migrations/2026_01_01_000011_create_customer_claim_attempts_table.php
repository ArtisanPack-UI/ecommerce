<?php

/**
 * Creates the `customer_claim_attempts` table (engine spec §3.22).
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
        Schema::create( 'customer_claim_attempts', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'customer_id' );
            $table->string( 'order_number', 50 )->nullable();
            $table->string( 'ip_address', 45 )->nullable();
            $table->boolean( 'was_success' )->default( false );
            $table->timestamp( 'created_at' )->nullable();

            $table->index(
                [ 'customer_id', 'created_at' ],
                'customer_claim_attempts_customer_time_idx',
            );

            $table->foreign( 'customer_id', 'customer_claim_attempts_customer_fk' )
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
        Schema::dropIfExists( 'customer_claim_attempts' );
    }
};
