<?php

/**
 * Creates the `product_attributes` table (engine spec §3.4).
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
        Schema::create( 'product_attributes', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->foreignId( 'product_id' )
                ->constrained( 'products' )
                ->cascadeOnDelete();
            $table->string( 'key', 60 );
            $table->string( 'label', 120 );
            $table->unsignedInteger( 'position' )->default( 0 );
            $table->boolean( 'is_variation' )->default( true );
            $table->timestamps();

            $table->unique( [ 'product_id', 'key' ], 'product_attributes_key_uk' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'product_attributes' );
    }
};
