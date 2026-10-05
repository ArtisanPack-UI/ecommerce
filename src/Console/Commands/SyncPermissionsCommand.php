<?php

/**
 * SyncPermissionsCommand.
 *
 * `php artisan ecommerce:sync-permissions` — registers every engine
 * ability as a cms-framework RBAC permission and gives them to the
 * `shop-manager` role (engine issue #151). Also runs after each `migrate`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Console\Commands;

use ArtisanPackUI\Ecommerce\Auth\CmsFrameworkPermissions;
use Illuminate\Console\Command;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SyncPermissionsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ecommerce:sync-permissions';

    /**
     * @var string
     */
    protected $description = 'Register the ecommerce abilities as cms-framework permissions and the shop-manager role.';

    /**
     * @since 1.0.0
     *
     * @param  CmsFrameworkPermissions  $permissions  cms-framework bridge.
     *
     * @return int
     */
    public function handle( CmsFrameworkPermissions $permissions ): int
    {
        if ( ! $permissions->isAvailable() ) {
            $this->warn( 'cms-framework is not installed (or artisanpack.ecommerce.cms_framework.enabled is off); nothing to sync.' );

            return self::SUCCESS;
        }

        $count = $permissions->sync();

        $this->info( sprintf( 'Synced %d ecommerce permission(s) and the %s role.', $count, CmsFrameworkPermissions::ROLE ) );

        return self::SUCCESS;
    }
}
