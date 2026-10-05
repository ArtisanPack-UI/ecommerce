<?php

/**
 * AccountMenuRegistry.
 *
 * The shopper's account navigation, shared by every storefront family
 * (#179), mirroring {@see AdminMenuRegistry}. Satellites add entries
 * (subscriptions, wishlists); storefronts render {@see self::visibleTo()}.
 *
 * Entry: `label` (string or closure, required), `route` (a route name the
 * storefront defines, or a URL, required), `icon`, `position` (default
 * 100), `visible` (bool, or a callable given the customer returning one),
 * and `satellite` (only listed while that satellite is active). Core
 * entries use `ecommerce.account.{key}` route names.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Registries;

use ArtisanPackUI\Ecommerce\Models\Customer;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class AccountMenuRegistry
{
    /**
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_POSITION = 100;

    /**
     * Registered entries by key.
     *
     * @since 1.0.0
     *
     * @var array<string, array{key: string, label: Closure|string, route: string, icon: string|null, position: int, visible: bool|callable, satellite: string|null}>
     */
    private array $entries = [];

    /**
     * @since 1.0.0
     *
     * @param  Application  $container  Container.
     */
    public function __construct( private readonly Application $container )
    {
    }

    /**
     * Adds (or, outside local and testing, replaces) an entry.
     *
     * @since 1.0.0
     *
     * @param  string                $key    Unique key.
     * @param  array<string, mixed>  $entry  Entry (see class docblock).
     *
     * @throws InvalidArgumentException When the entry is malformed, or duplicated in local/testing.
     *
     * @return void
     */
    public function register( string $key, array $entry ): void
    {
        $key   = trim( $key );
        $label = $entry['label'] ?? null;
        $route = $entry['route'] ?? null;

        if ( '' === $key ) {
            throw new InvalidArgumentException( 'Account menu entry key must not be empty.' );
        }

        if ( ! ( $label instanceof Closure ) && ( ! is_string( $label ) || '' === trim( $label ) ) ) {
            throw new InvalidArgumentException( sprintf( 'Account menu entry "%s" needs a label (a string or a closure).', $key ) );
        }

        if ( ! is_string( $route ) || '' === trim( $route ) ) {
            throw new InvalidArgumentException( sprintf( 'Account menu entry "%s" needs a route name or URL.', $key ) );
        }

        $visible = $entry['visible'] ?? true;

        if ( ! is_bool( $visible ) && ! is_callable( $visible ) ) {
            throw new InvalidArgumentException( sprintf( 'Account menu entry "%s" visible must be a bool or a callable.', $key ) );
        }

        if ( isset( $this->entries[ $key ] ) ) {
            $message = sprintf( 'Account menu entry "%s" is already registered; the new entry overwrites the previous one.', $key );

            if ( in_array( $this->container->environment(), [ 'local', 'testing' ], true ) ) {
                throw new InvalidArgumentException( $message );
            }

            Log::warning( $message );
        }

        $this->entries[ $key ] = [
            'key'       => $key,
            'label'     => $label,
            'route'     => trim( $route ),
            'icon'      => isset( $entry['icon'] ) && is_string( $entry['icon'] ) && '' !== $entry['icon'] ? $entry['icon'] : null,
            'position'  => (int) ( $entry['position'] ?? self::DEFAULT_POSITION ),
            'visible'   => $visible,
            'satellite' => isset( $entry['satellite'] ) && is_string( $entry['satellite'] ) ? $entry['satellite'] : null,
        ];
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $key  Key.
     *
     * @return void
     */
    public function forget( string $key ): void
    {
        unset( $this->entries[ $key ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $key  Key.
     *
     * @return bool
     */
    public function has( string $key ): bool
    {
        return isset( $this->entries[ $key ] );
    }

    /**
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys( $this->entries );
    }

    /**
     * The entries `$customer` sees, in order, through
     * `ap.ecommerce.accountMenu.entries`.
     *
     * @since 1.0.0
     *
     * @param  Customer|null  $customer  Signed-in customer.
     *
     * @return array<int, array{key: string, label: string, route: string, url: string|null, icon: string|null, position: int}>
     */
    public function visibleTo( ?Customer $customer ): array
    {
        $satellites = $this->container->make( SatelliteRegistry::class );
        $order      = array_flip( array_keys( $this->entries ) );
        $entries    = array_filter( $this->entries, function ( array $entry ) use ( $satellites, $customer ): bool {
            if ( null !== $entry['satellite'] && ! $satellites->isActive( $entry['satellite'] ) ) {
                return false;
            }

            if ( is_bool( $entry['visible'] ) ) {
                return $entry['visible'];
            }

            try {
                return true === ( $entry['visible'] )( $customer );
            } catch ( Throwable $exception ) {
                report( $exception );

                return false;
            }
        } );

        uasort( $entries, static fn ( array $a, array $b ): int => [ $a['position'], $order[ $a['key'] ] ] <=> [ $b['position'], $order[ $b['key'] ] ] );

        $resolved = array_values( array_map( static fn ( array $entry ): array => [
            'key'      => $entry['key'],
            'label'    => $entry['label'] instanceof Closure ? (string) ( $entry['label'] )() : $entry['label'],
            'route'    => $entry['route'],
            'url'      => self::url( $entry['route'] ),
            'icon'     => $entry['icon'],
            'position' => $entry['position'],
        ], $entries ) );

        return array_values( (array) applyFilters( 'ap.ecommerce.accountMenu.entries', $resolved, $customer ) );
    }

    /**
     * The URL of a route name the storefront defines, the value itself
     * when it is a URL, or null.
     *
     * @since 1.0.0
     *
     * @param  string  $route  Route name or URL.
     *
     * @return string|null
     */
    protected static function url( string $route ): ?string
    {
        if ( Route::has( $route ) ) {
            try {
                return route( $route );
            } catch ( Throwable ) {
                return null;
            }
        }

        return str_contains( $route, '/' ) ? $route : null;
    }
}
