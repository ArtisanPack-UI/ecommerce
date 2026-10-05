<?php

/**
 * CmsFrameworkPermissions.
 *
 * The engine's half of the cms-framework integration (engine issue #151;
 * decision recorded in engine spec §6.18). When cms-framework is installed:
 *
 * - every engine ability in {@see AbilityCatalog} is registered as an RBAC
 *   permission (slug `ecommerce.{resource}.{action}`), and a
 *   `shop-manager` role holding all of them is created — once, for every
 *   admin satellite (Livewire, React, Vue);
 * - each ability is defined as a Gate that only defers to the
 *   `ecommerce.admin` umbrella, so {@see EcommerceAuthorizer} consults it
 *   and rbac's `Gate::before` can grant it from the seeded permission. A
 *   host's own `Gate::define()` for the same ability wins.
 *
 * Permissions are synced by `php artisan ecommerce:sync-permissions` and
 * after every `migrate` run. Admin navigation is not wired here: each admin
 * satellite adds its own nav to cms-framework, reading satellite entries
 * from {@see \ArtisanPackUI\Ecommerce\Registries\AdminMenuRegistry}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Auth;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CmsFrameworkPermissions
{
    /**
     * Slug of the role that holds every engine permission.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ROLE = 'shop-manager';

    /**
     * Whether cms-framework's RBAC helpers are loaded and the integration
     * is switched on (`artisanpack.ecommerce.cms_framework.enabled`).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return (bool) config( 'artisanpack.ecommerce.cms_framework.enabled', true )
            && function_exists( 'ap_register_permission' )
            && function_exists( 'ap_register_role' )
            && function_exists( 'ap_add_permission_to_role' );
    }

    /**
     * Defines each engine ability the host has not defined, deferring to
     * the `ecommerce.admin` umbrella. rbac's `Gate::before` decides first
     * whenever a permission with that slug exists.
     *
     * @since 1.0.0
     *
     * @return int How many abilities were defined.
     */
    public function defineGates(): int
    {
        $defined = 0;

        foreach ( AbilityCatalog::abilities() as $ability ) {
            if ( Gate::has( $ability ) ) {
                continue;
            }

            // Untyped `$user` on purpose: Gate denies guests for it.
            Gate::define( $ability, static fn ( $user ): bool => Gate::has( 'ecommerce.admin' ) && Gate::forUser( $user )->allows( 'ecommerce.admin' ) );
            ++$defined;
        }

        return $defined;
    }

    /**
     * Registers every engine ability as an RBAC permission and gives them
     * all to the `shop-manager` role. Safe to run repeatedly; it never
     * takes a permission away from a role.
     *
     * @since 1.0.0
     *
     * @return int How many permissions were synced.
     */
    public function sync(): int
    {
        $abilities = AbilityCatalog::abilities();

        $this->registerRole( self::ROLE, __( 'Shop manager' ) );

        foreach ( $abilities as $ability ) {
            $this->registerPermission( $ability, $this->permissionName( $ability ) );
            $this->addPermissionToRole( self::ROLE, $ability );
        }

        return count( $abilities );
    }

    /**
     * Human-readable permission name, e.g. "Ecommerce: Order — Edit Fulfilled".
     *
     * @since 1.0.0
     *
     * @param  string  $ability  `ecommerce.{resource}.{action}`.
     *
     * @return string
     */
    public function permissionName( string $ability ): string
    {
        [ , $resource, $action ] = array_pad( explode( '.', $ability, 3 ), 3, '' );

        return __( 'Ecommerce: :resource — :action', [ 'resource' => Str::headline( $resource ), 'action' => Str::headline( $action ) ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $slug  Role slug.
     * @param  string  $name  Role name.
     *
     * @return void
     */
    protected function registerRole( string $slug, string $name ): void
    {
        ap_register_role( $slug, $name );
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $slug  Permission slug (the ability).
     * @param  string  $name  Permission name.
     *
     * @return void
     */
    protected function registerPermission( string $slug, string $name ): void
    {
        ap_register_permission( $slug, $name );
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $role        Role slug.
     * @param  string  $permission  Permission slug.
     *
     * @return void
     */
    protected function addPermissionToRole( string $role, string $permission ): void
    {
        ap_add_permission_to_role( $role, $permission );
    }
}
