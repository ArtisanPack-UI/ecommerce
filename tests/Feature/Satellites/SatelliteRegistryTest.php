<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Satellite;
use ArtisanPackUI\Ecommerce\ProductTypes\MissingProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\Ecommerce\Satellites\SatelliteDescriptor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses( RefreshDatabase::class );

/**
 * A satellite-owned product type (outside the engine namespace).
 */
final class AcmeSubscriptionProductType extends SimpleProductType
{
    public function key(): string
    {
        return 'subscription';
    }
}

function subscriptionsDescriptor( array $overrides = [] ): array
{
    return array_merge( [
        'package_name'    => 'acme/ecommerce-subscriptions',
        'version'         => '1.2.0',
        'label'           => 'Subscriptions',
        'config_keys'     => [ 'ecommerce-subscriptions' ],
        'meta_namespaces' => [ 'orders.meta.subscription' ],
        'tables'          => [ 'subscriptions' ],
        'columns'         => [ 'orders' => [ 'subscription_id' ] ],
        'product_types'   => [ 'subscription' ],
    ], $overrides );
}

it( 'registers a satellite in memory and reports it active', function (): void {
    $registry = new SatelliteRegistry( app() );

    expect( $registry->register( subscriptionsDescriptor() ) )->toBeTrue()
        ->and( $registry->has( 'acme/ecommerce-subscriptions' ) )->toBeTrue()
        ->and( $registry->get( 'acme/ecommerce-subscriptions' )->columns )->toBe( [ 'orders' => [ 'subscription_id' ] ] )
        ->and( Satellite::query()->count() )->toBe( 0 );
} );

it( 'rejects invalid descriptors and double registration in testing', function (): void {
    $registry = new SatelliteRegistry( app() );

    expect( fn () => $registry->register( subscriptionsDescriptor( [ 'package_name' => 'not a package' ] ) ) )
        ->toThrow( InvalidArgumentException::class );

    $registry->register( subscriptionsDescriptor() );

    expect( fn () => $registry->register( subscriptionsDescriptor() ) )->toThrow( InvalidArgumentException::class );
} );

it( 'upserts rows on sync without touching install state', function (): void {
    $registry = new SatelliteRegistry( app() );
    $registry->register( subscriptionsDescriptor() );
    $registry->sync();

    $row = Satellite::query()->sole();

    expect( $row->version )->toBe( '1.2.0' )
        ->and( $row->owned_columns )->toBe( [ 'orders' => [ 'subscription_id' ] ] )
        ->and( $row->meta_namespaces )->toBe( [ 'orders.meta.subscription' ] );

    $row->forceFill( [ 'uninstalled_at' => now() ] )->save();

    $next = new SatelliteRegistry( app() );
    $next->register( SatelliteDescriptor::fromArray( subscriptionsDescriptor( [ 'version' => '1.3.0' ] ) ) );
    $next->sync();

    expect( Satellite::query()->count() )->toBe( 1 )
        ->and( Satellite::query()->sole()->version )->toBe( '1.3.0' )
        ->and( Satellite::query()->sole()->isUninstalled() )->toBeTrue();
} );

it( 'reports an uninstalled satellite inactive and drops its product types after boot', function (): void {
    Satellite::factory()->uninstalled()->create( [ 'package_name' => 'acme/ecommerce-subscriptions' ] );
    app( ProductTypeRegistry::class )->register( 'subscription', AcmeSubscriptionProductType::class );

    $registry = new SatelliteRegistry( app() );

    expect( $registry->register( subscriptionsDescriptor() ) )->toBeFalse()
        ->and( $registry->isActive( 'acme/ecommerce-subscriptions' ) )->toBeFalse()
        ->and( $registry->active() )->toBe( [] )
        ->and( app( ProductTypeRegistry::class )->get( 'subscription' ) )->toBeInstanceOf( MissingProductType::class );
} );

it( 'caches the uninstalled set until flushed', function (): void {
    $registry = new SatelliteRegistry( app() );
    $row      = Satellite::factory()->create( [ 'package_name' => 'acme/ecommerce-subscriptions' ] );

    expect( $registry->isActive( 'acme/ecommerce-subscriptions' ) )->toBeTrue()
        ->and( Cache::get( SatelliteRegistry::CACHE_KEY ) )->toBe( [] );

    $row->forceFill( [ 'uninstalled_at' => now() ] )->save();

    expect( ( new SatelliteRegistry( app() ) )->isActive( 'acme/ecommerce-subscriptions' ) )->toBeTrue();

    $registry->flush();

    expect( $registry->isActive( 'acme/ecommerce-subscriptions' ) )->toBeFalse();
} );

it( 'treats every satellite as active when the table is missing', function (): void {
    Schema::drop( 'ecommerce_satellites' );

    $registry = new SatelliteRegistry( app() );

    expect( $registry->register( subscriptionsDescriptor() ) )->toBeTrue()
        ->and( Cache::has( SatelliteRegistry::CACHE_KEY ) )->toBeFalse();
} );

it( 'records a verification hash for a registered satellite', function (): void {
    $registry = new SatelliteRegistry( app() );
    $registry->register( subscriptionsDescriptor() );
    $hash = hash( 'sha256', 'report' );

    $registry->recordVerification( 'acme/ecommerce-subscriptions', strtoupper( $hash ) );

    expect( Satellite::query()->sole()->verified_report_hash )->toBe( $hash );
    expect( fn () => $registry->recordVerification( 'acme/ecommerce-subscriptions', 'nope' ) )->toThrow( InvalidArgumentException::class );
    expect( fn () => $registry->recordVerification( 'acme/unknown', $hash ) )->toThrow( RuntimeException::class );
} );

it( 'never forgets an engine product type an uninstalled satellite claims', function (): void {
    Satellite::factory()->uninstalled()->create( [ 'package_name' => 'acme/ecommerce-subscriptions' ] );

    app( ProductTypeRegistry::class )->register( 'subscription', AcmeSubscriptionProductType::class );
    ( new SatelliteRegistry( app() ) )->register( subscriptionsDescriptor( [ 'product_types' => [ SimpleProductType::KEY, 'subscription' ] ] ) );

    expect( app( ProductTypeRegistry::class )->get( SimpleProductType::KEY ) )->toBeInstanceOf( SimpleProductType::class )
        ->and( app( ProductTypeRegistry::class )->get( 'subscription' ) )->toBeInstanceOf( MissingProductType::class );
} );

it( 'keeps a product type another active satellite also declares', function (): void {
    Satellite::factory()->uninstalled()->create( [ 'package_name' => 'acme/ecommerce-subscriptions' ] );
    app( ProductTypeRegistry::class )->register( 'subscription', AcmeSubscriptionProductType::class );

    $registry = new SatelliteRegistry( app() );
    $registry->register( subscriptionsDescriptor( [ 'package_name' => 'acme/ecommerce-subscriptions-pro' ] ) );
    $registry->register( subscriptionsDescriptor() );

    expect( app( ProductTypeRegistry::class )->get( 'subscription' ) )->toBeInstanceOf( AcmeSubscriptionProductType::class );
} );

it( 'falls back to the database when the cache store is down', function (): void {
    Satellite::factory()->uninstalled()->create( [ 'package_name' => 'acme/ecommerce-subscriptions' ] );

    Cache::shouldReceive( 'get' )->andThrow( new RuntimeException( 'Redis is down' ) );
    Cache::shouldReceive( 'forever' )->andThrow( new RuntimeException( 'Redis is down' ) );

    $registry = new SatelliteRegistry( app() );

    expect( $registry->isActive( 'acme/ecommerce-subscriptions' ) )->toBeFalse();
} );

it( 'clears a recorded verification when the satellite version changes', function (): void {
    $registry = new SatelliteRegistry( app() );
    $registry->register( subscriptionsDescriptor() );
    $registry->recordVerification( 'acme/ecommerce-subscriptions', hash( 'sha256', 'report' ) );

    $same = new SatelliteRegistry( app() );
    $same->register( subscriptionsDescriptor() );
    $same->sync();

    expect( Satellite::query()->sole()->verified_report_hash )->not->toBeNull();

    $next = new SatelliteRegistry( app() );
    $next->register( subscriptionsDescriptor( [ 'version' => '2.0.0' ] ) );
    $next->sync();

    expect( Satellite::query()->sole()->verified_report_hash )->toBeNull();
} );
