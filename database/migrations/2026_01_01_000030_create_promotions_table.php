<?php

/**
 * Creates the `promotions` table (engine spec §3.23).
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
        Schema::create( 'promotions', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'key', 120 );
            $table->string( 'name', 255 );
            $table->text( 'description' )->nullable();
            $table->string( 'source_type', 80 );
            $table->boolean( 'is_exclusive' )->default( false );
            $table->integer( 'priority' )->default( 0 );
            $table->timestamp( 'starts_at' )->nullable();
            $table->timestamp( 'ends_at' )->nullable();
            $table->unsignedInteger( 'usage_limit_total' )->nullable();
            $table->unsignedInteger( 'usage_limit_per_customer' )->nullable();
            $table->unsignedInteger( 'times_used' )->default( 0 );
            $table->boolean( 'is_active' )->default( true );
            $table->timestamps();

            $table->unique( 'key', 'promotions_key_uk' );
            $table->index( [ 'is_active', 'starts_at', 'ends_at' ], 'promotions_active_range_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'promotions' );
    }
};
