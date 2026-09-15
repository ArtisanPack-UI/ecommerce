<?php

/**
 * Creates the `order_notes` table (engine spec §3.18).
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
        Schema::create( 'order_notes', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'order_id' );
            $table->unsignedBigInteger( 'author_user_id' )->nullable();
            $table->text( 'body' );
            $table->boolean( 'is_customer_visible' )->default( false );
            $table->timestamps();

            $table->index( 'order_id', 'order_notes_order_idx' );

            $table->foreign( 'order_id', 'order_notes_order_fk' )
                ->references( 'id' )
                ->on( 'orders' )
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
        Schema::dropIfExists( 'order_notes' );
    }
};
