<?php

/**
 * Creates the `product_variant_option_values` table (engine spec §3.6).
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
        Schema::create( 'product_variant_option_values', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->foreignId( 'product_variant_id' )
                ->constrained( 'product_variants' )
                ->cascadeOnDelete();
            $table->foreignId( 'product_attribute_id' )
                ->constrained( 'product_attributes' )
                ->cascadeOnDelete();
            $table->foreignId( 'product_attribute_value_id' )
                ->constrained( 'product_attribute_values' )
                ->cascadeOnDelete();

            $table->unique(
                [ 'product_variant_id', 'product_attribute_id' ],
                'product_variant_option_values_uk',
            );
            $table->index(
                'product_attribute_value_id',
                'product_variant_option_values_value_idx',
            );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'product_variant_option_values' );
    }
};
