<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Registries\AdminMenuRegistry;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->menu = app( AdminMenuRegistry::class );
} );

it( 'is bound as a singleton', function (): void {
    expect( app( AdminMenuRegistry::class ) )->toBe( $this->menu );
} );

it( 'registers, finds, and resolves an entry', function (): void {
    Route::get( '/admin/subscriptions', fn () => 'ok' )->name( 'subs.index' );
    Route::getRoutes()->refreshNameLookups();

    $this->menu->register( 'subscriptions', [
        'label'      => fn (): string => 'Subscriptions',
        'icon'       => 'arrow-path',
        'route'      => 'subs.index',
        'section'    => 'sales',
        'position'   => 30,
        'permission' => 'subscription.viewAny',
        'badge'      => fn (): int => 4,
    ] );

    expect( $this->menu->has( 'subscriptions' ) )->toBeTrue()
        ->and( $this->menu->has( 'missing' ) )->toBeFalse()
        ->and( $this->menu->get( 'subscriptions' ) )->toMatchArray( [
            'key'        => 'subscriptions',
            'label'      => 'Subscriptions',
            'icon'       => 'arrow-path',
            'route'      => 'subs.index',
            'url'        => url( '/admin/subscriptions' ),
            'section'    => 'sales',
            'position'   => 30,
            'permission' => 'subscription.viewAny',
            'badge'      => 4,
        ] );
} );

it( 'treats a route that is not a named route as a URL', function (): void {
    $this->menu->register( 'docs', [ 'label' => 'Docs', 'route' => 'https://example.com/docs' ] );

    expect( $this->menu->get( 'docs' )['url'] )->toBe( 'https://example.com/docs' )
        ->and( $this->menu->get( 'docs' )['position'] )->toBe( AdminMenuRegistry::DEFAULT_POSITION )
        ->and( $this->menu->get( 'docs' )['badge'] )->toBeNull();
} );

it( 'sorts unsectioned entries first, then by section order and position', function (): void {
    $this->menu->registerSection( 'catalog', 'Catalog', 20 );
    $this->menu->registerSection( 'sales', 'Sales', 10 );

    $this->menu->register( 'later-misc', [ 'label' => 'Misc', 'route' => '/m', 'section' => 'zzz' ] );
    $this->menu->register( 'products', [ 'label' => 'Products', 'route' => '/p', 'section' => 'catalog', 'position' => 5 ] );
    $this->menu->register( 'refunds', [ 'label' => 'Refunds', 'route' => '/r', 'section' => 'sales', 'position' => 20 ] );
    $this->menu->register( 'orders', [ 'label' => 'Orders', 'route' => '/o', 'section' => 'sales', 'position' => 10 ] );
    $this->menu->register( 'dashboard', [ 'label' => 'Dashboard', 'route' => '/d', 'position' => 999 ] );

    expect( array_column( $this->menu->all(), 'key' ) )->toBe( [ 'dashboard', 'orders', 'refunds', 'products', 'later-misc' ] )
        ->and( array_column( $this->menu->sections(), 'key' ) )->toBe( [ 'sales', 'catalog' ] );
} );

it( 'drops entries of an uninstalled satellite', function (): void {
    $satellites = app( SatelliteRegistry::class );
    $satellites->register( [ 'package_name' => 'acme/ecommerce-subscriptions', 'version' => '1.0.0' ] );
    $satellites->sync();

    $this->menu->register( 'subscriptions', [ 'label' => 'Subscriptions', 'route' => '/s', 'satellite' => 'acme/ecommerce-subscriptions' ] );
    $this->menu->register( 'orders', [ 'label' => 'Orders', 'route' => '/o' ] );

    expect( array_column( $this->menu->all(), 'key' ) )->toBe( [ 'subscriptions', 'orders' ] );

    $satellites->markUninstalled( 'acme/ecommerce-subscriptions' );

    expect( array_column( $this->menu->all(), 'key' ) )->toBe( [ 'orders' ] );
} );

it( 'hides a failing badge instead of breaking the nav', function (): void {
    $this->menu->register( 'orders', [ 'label' => 'Orders', 'route' => '/o', 'badge' => function (): int {
        throw new RuntimeException( 'db down' );
    } ] );

    expect( $this->menu->all()[0]['badge'] )->toBeNull();
} );

it( 'narrows entries to the abilities the viewer holds', function (): void {
    $this->menu->register( 'public', [ 'label' => 'Help', 'route' => '/h' ] );
    $this->menu->register( 'orders', [ 'label' => 'Orders', 'route' => '/o', 'permission' => 'order.viewAny' ] );
    $this->menu->register( 'settings', [ 'label' => 'Settings', 'route' => '/s', 'permission' => 'ecommerce.settings.view' ] );

    Gate::define( 'ecommerce.order.viewAny', fn (): bool => true );

    $user = new GenericUser( [ 'id' => 5 ] );

    expect( array_column( $this->menu->visibleTo( $user ), 'key' ) )->toBe( [ 'public', 'orders' ] )
        ->and( array_column( $this->menu->visibleTo( null ), 'key' ) )->toBe( [ 'public' ] );
} );

it( 'runs the resolved entries through the adminMenu filter', function (): void {
    $this->menu->register( 'orders', [ 'label' => 'Orders', 'route' => '/o' ] );

    addFilter( 'ap.ecommerce.adminMenu.entries', fn ( array $entries ): array => array_map( fn ( array $entry ): array => [ 'label' => 'Renamed' ] + $entry, $entries ) );

    expect( $this->menu->all()[0]['label'] )->toBe( 'Renamed' );
} );

it( 'rejects malformed entries and duplicate keys', function ( string $key, array $entry ): void {
    $this->menu->register( 'taken', [ 'label' => 'Taken', 'route' => '/t' ] );

    expect( fn () => $this->menu->register( $key, $entry ) )->toThrow( InvalidArgumentException::class );
} )->with( [
    'empty key'     => [ ' ', [ 'label' => 'X', 'route' => '/x' ] ],
    'no label'      => [ 'x', [ 'route' => '/x' ] ],
    'no route'      => [ 'x', [ 'label' => 'X' ] ],
    'bad badge'     => [ 'x', [ 'label' => 'X', 'route' => '/x', 'badge' => 'three' ] ],
    'duplicate key' => [ 'taken', [ 'label' => 'Again', 'route' => '/t' ] ],
] );

it( 'forgets an entry', function (): void {
    $this->menu->register( 'orders', [ 'label' => 'Orders', 'route' => '/o' ] );
    $this->menu->forget( 'orders' );

    expect( $this->menu->has( 'orders' ) )->toBeFalse()
        ->and( fn () => $this->menu->get( 'orders' ) )->toThrow( RuntimeException::class );
} );
