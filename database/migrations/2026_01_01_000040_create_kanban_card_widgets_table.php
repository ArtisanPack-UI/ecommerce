<?php

/**
 * Creates the `kanban_card_widgets` table.
 *
 * Persisted catalog of card widget types (engine spec §3.28). Widgets are
 * code (KanbanCardWidgetRegistry); a row here lets a store override a
 * widget's display label and default config without code.
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
        Schema::create( 'kanban_card_widgets', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'key', 120 );
            $table->string( 'label', 120 );
            $table->json( 'default_config' );
            $table->string( 'provided_by', 191 );

            $table->unique( 'key', 'kanban_card_widgets_key_uk' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'kanban_card_widgets' );
    }
};
