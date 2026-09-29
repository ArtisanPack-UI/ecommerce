<?php

/**
 * Creates the `shipping_methods` table (engine spec §3.25).
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
        Schema::create( 'shipping_methods', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'zone_id' );
            $table->string( 'key', 120 );
            $table->string( 'label', 255 );
            $table->json( 'config' );
            $table->string( 'tax_class_key', 60 )->nullable();
            $table->boolean( 'is_active' )->default( true );
            $table->unsignedInteger( 'position' )->default( 0 );
            $table->timestamps();

            $table->index( 'zone_id', 'shipping_methods_zone_idx' );

            $table->foreign( 'zone_id', 'shipping_methods_zone_fk' )
                ->references( 'id' )
                ->on( 'shipping_zones' )
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
        Schema::dropIfExists( 'shipping_methods' );
    }
};
