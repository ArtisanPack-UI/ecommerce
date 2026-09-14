<?php

/**
 * Creates the `product_attribute_values` table (engine spec §3.5).
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
        Schema::create( 'product_attribute_values', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->foreignId( 'product_attribute_id' )
                ->constrained( 'product_attributes' )
                ->cascadeOnDelete();
            $table->string( 'value', 120 );
            $table->string( 'label', 120 );
            $table->string( 'swatch', 60 )->nullable();
            $table->unsignedInteger( 'position' )->default( 0 );

            $table->unique(
                [ 'product_attribute_id', 'value' ],
                'product_attribute_values_value_uk',
            );

            // Composite unique so `product_variant_option_values` can
            // reference `(product_attribute_value_id, product_attribute_id)`
            // as a foreign key — enforcing at the schema level that a
            // chosen value actually belongs to the chosen attribute.
            $table->unique(
                [ 'id', 'product_attribute_id' ],
                'product_attribute_values_id_attr_uk',
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
        Schema::dropIfExists( 'product_attribute_values' );
    }
};
