<?php

/**
 * Creates the `shipping_zones` table (engine spec §3.25).
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
        Schema::create( 'shipping_zones', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'name', 255 );
            $table->json( 'country_codes' );
            $table->json( 'region_codes' )->nullable();
            $table->json( 'postal_patterns' )->nullable();
            $table->integer( 'priority' )->default( 0 );
            $table->boolean( 'is_active' )->default( true );
            $table->timestamps();
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'shipping_zones' );
    }
};
