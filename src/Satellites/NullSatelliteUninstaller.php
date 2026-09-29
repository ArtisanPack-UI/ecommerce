<?php

/**
 * NullSatelliteUninstaller.
 *
 * Default {@see SatelliteUninstaller} used when a satellite declares none
 * (or its class is gone because the package was already removed).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Satellites;

use ArtisanPackUI\Ecommerce\Contracts\SatelliteUninstaller;

/**
 * No-op uninstaller.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class NullSatelliteUninstaller implements SatelliteUninstaller
{
    /**
     * @since 1.0.0
     *
     * @param  SatelliteDescriptor  $satellite  The satellite being uninstalled.
     * @param  bool                 $purge      Whether its tables are about to be dropped too.
     *
     * @return void
     */
    public function uninstall( SatelliteDescriptor $satellite, bool $purge ): void
    {
        // Nothing long-lived to unbind.
    }
}
