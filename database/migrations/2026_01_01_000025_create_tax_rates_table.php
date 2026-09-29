<?php

/**
 * Creates the `tax_rates` table (engine spec §3.24).
 *
 * `rate_ubps` stores the rate as an integer in micro-basis-points so no
 * tax rate is ever handled as a float (§2.4). `tax_class_key` is a soft
 * reference to `tax_classes.key` — the service layer validates it on write.
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
        Schema::create( 'tax_rates', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'tax_class_key', 60 );
            $table->char( 'country_code', 2 );
            $table->string( 'region_code', 10 )->nullable();
            $table->string( 'postal_pattern', 60 )->nullable();
            $table->integer( 'rate_ubps' );
            $table->boolean( 'is_compound' )->default( false );
            $table->integer( 'priority' )->default( 0 );
            $table->string( 'label', 120 );
            $table->boolean( 'is_shipping_taxable' )->default( false );
            $table->boolean( 'is_active' )->default( true );
            $table->timestamps();

            $table->index( [ 'country_code', 'region_code' ], 'tax_rates_country_region_idx' );
            $table->index( [ 'tax_class_key', 'is_active' ], 'tax_rates_class_active_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'tax_rates' );
    }
};
