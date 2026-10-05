<?php

/**
 * Creates the `ecommerce_settings` table.
 *
 * Store settings edited from an admin (engine issue #145). Each row holds
 * one allow-listed key from {@see ArtisanPackUI\Ecommerce\Registries\SettingsRegistry}
 * and overlays the matching `config( 'artisanpack.ecommerce' )` value.
 * Secrets (gateway credentials, signing keys) are never stored here.
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
        Schema::create( 'ecommerce_settings', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'key', 191 );
            $table->json( 'value' )->nullable();
            $table->timestamps();

            $table->unique( 'key', 'ecommerce_settings_key_uk' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_settings' );
    }
};
