<?php

/**
 * Makes `ecommerce_kanban_card_widgets.label` nullable.
 *
 * A stored widget row may hold only a default config. Its label is then
 * null, so the widget's own (translated) label shows, rather than a copy
 * frozen in whichever locale saved the row.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * @since 1.2.0
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table( 'ecommerce_kanban_card_widgets', function ( Blueprint $table ): void {
            $table->string( 'label', 120 )->nullable()->change();
        } );
    }

    /**
     * Rows without a label take their key, so the column can be required
     * again.
     *
     * @since 1.2.0
     *
     * @return void
     */
    public function down(): void
    {
        DB::table( 'ecommerce_kanban_card_widgets' )->whereNull( 'label' )->update( [ 'label' => DB::raw( DB::getQueryGrammar()->wrap( 'key' ) ) ] );

        Schema::table( 'ecommerce_kanban_card_widgets', function ( Blueprint $table ): void {
            $table->string( 'label', 120 )->nullable( false )->change();
        } );
    }
};
