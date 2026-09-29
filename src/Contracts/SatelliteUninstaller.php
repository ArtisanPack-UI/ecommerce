<?php

/**
 * SatelliteUninstaller contract.
 *
 * Invoked by `php artisan ecommerce:satellite:uninstall` so a satellite
 * can unbind its long-lived services (queue workers, scheduled jobs,
 * broadcast channels, external webhooks) before the engine marks it
 * uninstalled. Parent plan §16.6.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Satellites\SatelliteDescriptor;

/**
 * Tears down a satellite's runtime footprint.
 *
 * Implementations must be idempotent — the command may run twice (e.g.
 * once without and later with `--purge`). They must not drop the
 * satellite's tables: with `--purge` the engine rolls back the
 * satellite's migrations itself, after this method returns.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface SatelliteUninstaller
{
    /**
     * Unbinds the satellite's long-lived services.
     *
     * @since 1.0.0
     *
     * @param  SatelliteDescriptor  $satellite  The satellite being uninstalled.
     * @param  bool                 $purge      Whether its tables are about to be dropped too.
     *
     * @return void
     */
    public function uninstall( SatelliteDescriptor $satellite, bool $purge ): void;
}
