<?php

/**
 * AdminMenuRegistry.
 *
 * Framework-neutral admin navigation entries (engine spec §5 row 15,
 * engine issue #144). Satellites register entries from `boot()`; the
 * Livewire, React, and Vue admin packages read {@see self::all()} (or
 * {@see self::visibleTo()}) and merge the entries into their own nav:
 *
 * ```php
 * app( AdminMenuRegistry::class )->register( 'subscriptions', [
 *     'label'      => fn (): string => __( 'Subscriptions' ),
 *     'icon'       => 'arrow-path',
 *     'route'      => 'ecommerce-subscriptions.admin.index',
 *     'section'    => 'sales',
 *     'position'   => 30,
 *     'permission' => 'subscription.viewAny',
 *     'badge'      => fn (): int => Subscription::query()->pastDue()->count(),
 *     'satellite'  => 'artisanpack-ui/ecommerce-subscriptions',
 * ] );
 * ```
 *
 * Entry keys: `label` (string, or a closure so it is translated in the
 * viewer's locale), `route` (a route name or a URL), and optionally
 * `icon`, `section`, `position` (default 100), `permission` (an engine
 * ability `{resource}.{action}`), `badge` (an int or a callable returning
 * one), and `satellite` (the registering package; its entries disappear
 * while that satellite is uninstalled). Sections are optional groups,
 * ordered by {@see self::registerSection()}.
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

use ArtisanPackUI\Ecommerce\Auth\EcommerceAuthorizer;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class AdminMenuRegistry
{
    /**
     * Position used when an entry or section does not give one.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_POSITION = 100;

    /**
     * Registered entries, keyed by entry key.
     *
     * @since 1.0.0
     *
     * @var array<string, array{key: string, label: Closure|string, icon: string|null, route: string, section: string|null, position: int, permission: string|null, badge: callable|int|null, satellite: string|null}>
     */
    private array $entries = [];

    /**
     * Registered sections, keyed by section key.
     *
     * @since 1.0.0
     *
     * @var array<string, array{key: string, label: Closure|string, position: int}>
     */
    private array $sections = [];

    /**
     * @since 1.0.0
     *
     * @param  Application  $container  Application (environment, satellite registry).
     */
    public function __construct( private readonly Application $container )
    {
    }

    /**
     * Registers an admin nav entry under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string                $key    Entry key (kebab-case).
     * @param  array<string, mixed>  $entry  Entry (see the class docblock).
     *
     * @throws InvalidArgumentException When the entry is malformed, or the key collides in `local`/`testing`.
     *
     * @return void
     */
    public function register( string $key, array $entry ): void
    {
        $key = trim( $key );

        if ( '' === $key ) {
            throw new InvalidArgumentException( 'Admin menu entry key must not be empty.' );
        }

        $label = $entry['label'] ?? null;
        $route = $entry['route'] ?? null;
        $badge = $entry['badge'] ?? null;

        if ( ! ( $label instanceof Closure ) && ( ! is_string( $label ) || '' === trim( $label ) ) ) {
            throw new InvalidArgumentException( sprintf( 'Admin menu entry "%s" needs a label (a string or a closure).', $key ) );
        }

        if ( ! is_string( $route ) || '' === trim( $route ) ) {
            throw new InvalidArgumentException( sprintf( 'Admin menu entry "%s" needs a route name or URL.', $key ) );
        }

        if ( null !== $badge && ! is_int( $badge ) && ! is_callable( $badge ) ) {
            throw new InvalidArgumentException( sprintf( 'Admin menu entry "%s" badge must be an int or a callable returning one.', $key ) );
        }

        $this->warnOrThrowOnDuplicate( isset( $this->entries[ $key ] ), sprintf( 'Admin menu entry "%s"', $key ) );

        $this->entries[ $key ] = [
            'key'        => $key,
            'label'      => $label,
            'icon'       => self::optionalString( $entry['icon'] ?? null ),
            'route'      => trim( $route ),
            'section'    => self::optionalString( $entry['section'] ?? null ),
            'position'   => (int) ( $entry['position'] ?? self::DEFAULT_POSITION ),
            'permission' => self::optionalString( $entry['permission'] ?? null ),
            'badge'      => $badge,
            'satellite'  => self::optionalString( $entry['satellite'] ?? null ),
        ];
    }

    /**
     * Registers (or relabels) a section entries can be grouped under.
     * Sections that entries name without registering sort after the
     * registered ones, by key.
     *
     * @since 1.0.0
     *
     * @param  string          $key       Section key.
     * @param  Closure|string  $label     Label, or a closure returning it.
     * @param  int             $position  Sort order.
     *
     * @return void
     */
    public function registerSection( string $key, Closure|string $label, int $position = self::DEFAULT_POSITION ): void
    {
        $key = trim( $key );

        if ( '' === $key ) {
            throw new InvalidArgumentException( 'Admin menu section key must not be empty.' );
        }

        $this->sections[ $key ] = [ 'key' => $key, 'label' => $label, 'position' => $position ];
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $key  Entry key.
     *
     * @return bool
     */
    public function has( string $key ): bool
    {
        return isset( $this->entries[ $key ] );
    }

    /**
     * The resolved entry under `$key`, whether or not its satellite is active.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Entry key.
     *
     * @throws RuntimeException When nothing is registered under `$key`.
     *
     * @return array{key: string, label: string, icon: string|null, route: string, url: string|null, section: string|null, position: int, permission: string|null, badge: int|null, satellite: string|null}
     */
    public function get( string $key ): array
    {
        if ( ! isset( $this->entries[ $key ] ) ) {
            throw new RuntimeException( sprintf( 'No admin menu entry registered under "%s".', $key ) );
        }

        return $this->resolve( $this->entries[ $key ] );
    }

    /**
     * Removes the entry under `$key`, if any.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Entry key.
     *
     * @return void
     */
    public function forget( string $key ): void
    {
        unset( $this->entries[ $key ] );
    }

    /**
     * Every entry whose satellite is active, resolved and sorted: entries
     * with no section first, then by section order, then `position`, then
     * registration order. Runs through `ap.ecommerce.adminMenu.entries`.
     *
     * @since 1.0.0
     *
     * @return array<int, array{key: string, label: string, icon: string|null, route: string, url: string|null, section: string|null, position: int, permission: string|null, badge: int|null, satellite: string|null}>
     */
    public function all(): array
    {
        $satellites = $this->container->make( SatelliteRegistry::class );
        $order      = array_flip( array_keys( $this->entries ) );
        $entries    = array_filter(
            $this->entries,
            static fn ( array $entry ): bool => null === $entry['satellite'] || $satellites->isActive( $entry['satellite'] ),
        );

        uasort( $entries, fn ( array $a, array $b ): int => [ ...$this->sectionSortKey( $a['section'] ), $a['position'], $order[ $a['key'] ] ]
            <=> [ ...$this->sectionSortKey( $b['section'] ), $b['position'], $order[ $b['key'] ] ] );

        $resolved = array_values( array_map( fn ( array $entry ): array => $this->resolve( $entry ), $entries ) );

        return array_values( (array) applyFilters( 'ap.ecommerce.adminMenu.entries', $resolved ) );
    }

    /**
     * {@see self::all()} narrowed to the entries `$user` may see: entries
     * without a `permission`, and those whose ability the engine authorizer
     * grants.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable|null  $user  Viewer.
     *
     * @return array<int, array{key: string, label: string, icon: string|null, route: string, url: string|null, section: string|null, position: int, permission: string|null, badge: int|null, satellite: string|null}>
     */
    public function visibleTo( ?Authenticatable $user ): array
    {
        $authorizer = $this->container->make( EcommerceAuthorizer::class );

        return array_values( array_filter( $this->all(), static function ( array $entry ) use ( $authorizer, $user ): bool {
            if ( null === $entry['permission'] ) {
                return true;
            }

            $ability = str_starts_with( $entry['permission'], 'ecommerce.' ) ? substr( $entry['permission'], 10 ) : $entry['permission'];
            $parts   = explode( '.', $ability, 2 );

            return 2 === count( $parts ) && $authorizer->allows( $user, $parts[0], $parts[1] );
        } ) );
    }

    /**
     * Registered sections in order, labels resolved.
     *
     * @since 1.0.0
     *
     * @return array<int, array{key: string, label: string, position: int}>
     */
    public function sections(): array
    {
        $sections = $this->sections;

        uasort( $sections, static fn ( array $a, array $b ): int => [ $a['position'], $a['key'] ] <=> [ $b['position'], $b['key'] ] );

        return array_values( array_map( static fn ( array $section ): array => [
            'key'      => $section['key'],
            'label'    => self::label( $section['label'] ),
            'position' => $section['position'],
        ], $sections ) );
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
     * Resolves an entry's label, URL, and badge.
     *
     * @since 1.0.0
     *
     * @param  array{key: string, label: Closure|string, icon: string|null, route: string, section: string|null, position: int, permission: string|null, badge: callable|int|null, satellite: string|null}  $entry  Stored entry.
     *
     * @return array{key: string, label: string, icon: string|null, route: string, url: string|null, section: string|null, position: int, permission: string|null, badge: int|null, satellite: string|null}
     */
    protected function resolve( array $entry ): array
    {
        return [
            'key'        => $entry['key'],
            'label'      => self::label( $entry['label'] ),
            'icon'       => $entry['icon'],
            'route'      => $entry['route'],
            'url'        => $this->url( $entry['route'] ),
            'section'    => $entry['section'],
            'position'   => $entry['position'],
            'permission' => $entry['permission'],
            'badge'      => $this->badge( $entry ),
            'satellite'  => $entry['satellite'],
        ];
    }

    /**
     * The URL for a route name, or the value itself when it is not a
     * named route. `null` when the named route needs parameters.
     *
     * @since 1.0.0
     *
     * @param  string  $route  Route name or URL.
     *
     * @return string|null
     */
    protected function url( string $route ): ?string
    {
        if ( ! Route::has( $route ) ) {
            return $route;
        }

        try {
            return route( $route );
        } catch ( Throwable ) {
            return null;
        }
    }

    /**
     * The entry's badge count. A failing badge callback is reported and
     * hides the badge rather than breaking the nav.
     *
     * @since 1.0.0
     *
     * @param  array{key: string, badge: callable|int|null}  $entry  Stored entry.
     *
     * @return int|null
     */
    protected function badge( array $entry ): ?int
    {
        if ( null === $entry['badge'] || is_int( $entry['badge'] ) ) {
            return $entry['badge'];
        }

        try {
            $value = ( $entry['badge'] )();
        } catch ( Throwable $exception ) {
            report( $exception );

            return null;
        }

        return is_numeric( $value ) ? (int) $value : null;
    }

    /**
     * Sort key for a section: unsectioned first, registered sections by
     * position, unregistered ones last by key.
     *
     * @since 1.0.0
     *
     * @param  string|null  $section  Section key.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    protected function sectionSortKey( ?string $section ): array
    {
        if ( null === $section ) {
            return [ 0, 0, '' ];
        }

        if ( isset( $this->sections[ $section ] ) ) {
            return [ 1, $this->sections[ $section ]['position'], $section ];
        }

        return [ 2, 0, $section ];
    }

    /**
     * Throws in `local`/`testing`, warns elsewhere (engine spec §5).
     *
     * @since 1.0.0
     *
     * @param  bool    $duplicate  Whether the key is taken.
     * @param  string  $what       Subject for the message.
     *
     * @throws InvalidArgumentException When duplicated in `local`/`testing`.
     *
     * @return void
     */
    protected function warnOrThrowOnDuplicate( bool $duplicate, string $what ): void
    {
        if ( ! $duplicate ) {
            return;
        }

        $message = sprintf( '%s is already registered; the new entry overwrites the previous one.', $what );

        if ( in_array( $this->container->environment(), [ 'local', 'testing' ], true ) ) {
            throw new InvalidArgumentException( $message );
        }

        Log::warning( $message );
    }

    /**
     * @since 1.0.0
     *
     * @param  Closure|string  $label  Label or closure.
     *
     * @return string
     */
    protected static function label( Closure|string $label ): string
    {
        return $label instanceof Closure ? (string) $label() : $label;
    }

    /**
     * @since 1.0.0
     *
     * @param  mixed  $value  Raw value.
     *
     * @return string|null
     */
    protected static function optionalString( mixed $value ): ?string
    {
        return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
    }
}
