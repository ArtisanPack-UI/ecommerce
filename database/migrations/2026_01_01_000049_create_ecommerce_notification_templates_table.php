<?php

/**
 * Creates the `ecommerce_notification_templates` table.
 *
 * Store-owner-editable notification copy (engine spec §3.30, parent plan
 * §14.2). `subject` and `body` are Twig sources rendered inside the
 * sandbox; `variables` documents what the template may reference and
 * `preview_data` is the sample context for the editor's live preview.
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
        Schema::create( 'ecommerce_notification_templates', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'key', 120 );
            $table->string( 'channel', 60 );
            $table->string( 'locale', 10 )->default( 'en' );
            $table->text( 'subject' )->nullable();
            $table->longText( 'body' );
            $table->json( 'variables' );
            $table->json( 'preview_data' );
            $table->boolean( 'is_active' )->default( true );
            $table->timestamps();

            $table->unique( [ 'key', 'channel', 'locale' ], 'ecommerce_notification_templates_key_channel_locale_uk' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_notification_templates' );
    }
};
