<?php

/**
 * Creates the `ecommerce_satellites` table.
 *
 * Backing store for the {@see ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry}
 * (engine spec §3.32, parent plan §16.6). One row per satellite package
 * that has ever registered with the engine. `uninstalled_at` is set by
 * `ecommerce:satellite:uninstall` and cleared by
 * `ecommerce:satellite:reinstall`; the row itself is never deleted, so a
 * reinstall re-attaches the satellite's data without loss and
 * `ecommerce:satellite:audit` can still report the columns and tables it
 * left behind after a `composer remove`.
 *
 * `migrations_namespace` holds the satellite's migration paths (JSON array)
 * so `--purge` can roll them back even after the package stopped booting.
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
        Schema::create( 'ecommerce_satellites', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'package_name', 191 );
            $table->string( 'label', 191 )->nullable();
            $table->string( 'version', 60 );
            $table->text( 'migrations_namespace' )->nullable();
            $table->json( 'config_keys' );
            $table->json( 'meta_namespaces' );
            $table->json( 'owned_tables' );
            $table->json( 'owned_columns' );
            $table->json( 'product_types' );
            $table->string( 'uninstaller', 255 )->nullable();
            $table->char( 'verified_report_hash', 64 )->nullable();
            $table->timestamp( 'registered_at' );
            $table->timestamp( 'uninstalled_at' )->nullable();

            $table->unique( 'package_name', 'ecommerce_satellites_package_uk' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_satellites' );
    }
};
