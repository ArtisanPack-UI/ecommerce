<?php

/**
 * Creates the `customer_notification_preferences` table.
 *
 * Per-channel, per-category notification opt-ins for a customer (engine
 * spec §3.30, parent plan §14.3). `transactional` rows are kept for audit
 * only — transactional notifications always send.
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
        Schema::create( 'customer_notification_preferences', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'customer_id' );
            $table->string( 'channel', 60 );
            $table->string( 'category', 60 );
            $table->boolean( 'is_enabled' )->default( true );
            $table->timestamp( 'updated_at' )->nullable();

            $table->unique( [ 'customer_id', 'channel', 'category' ], 'customer_notification_preferences_uk' );

            $table->foreign( 'customer_id', 'customer_notification_preferences_customer_fk' )
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
        Schema::dropIfExists( 'customer_notification_preferences' );
    }
};
