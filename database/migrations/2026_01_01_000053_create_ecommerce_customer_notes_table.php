<?php

/**
 * Creates the `ecommerce_customer_notes` table.
 *
 * Internal staff notes on a customer. Customer notes are never shown to the
 * shopper, so unlike `order_notes` there is no visibility flag. Parent plan
 * §10.2 item 13.
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
        Schema::create( 'ecommerce_customer_notes', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'customer_id' );
            $table->unsignedBigInteger( 'author_user_id' )->nullable();
            $table->text( 'body' );
            $table->timestamps();

            $table->index( 'customer_id', 'ecommerce_customer_notes_customer_idx' );

            $table->foreign( 'customer_id', 'ecommerce_customer_notes_customer_fk' )
                ->references( 'id' )
                ->on( 'ecommerce_customers' )
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
        Schema::dropIfExists( 'ecommerce_customer_notes' );
    }
};
