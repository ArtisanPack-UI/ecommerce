<?php

/**
 * Creates the `coupons` table (engine spec §3.23).
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
        Schema::create( 'coupons', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'promotion_id' );
            $table->string( 'code', 80 );
            $table->timestamps();

            $table->unique( 'code', 'coupons_code_uk' );
            $table->index( 'promotion_id', 'coupons_promotion_idx' );

            $table->foreign( 'promotion_id', 'coupons_promotion_fk' )
                ->references( 'id' )
                ->on( 'promotions' )
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
        Schema::dropIfExists( 'coupons' );
    }
};
