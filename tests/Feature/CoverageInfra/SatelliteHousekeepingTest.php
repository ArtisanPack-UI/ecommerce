<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\SatelliteUninstaller;
use ArtisanPackUI\Ecommerce\Models\Satellite;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\Ecommerce\Satellites\NullSatelliteUninstaller;
use ArtisanPackUI\Ecommerce\Satellites\SatelliteDescriptor;
use ArtisanPackUI\Ecommerce\Satellites\SatelliteOrphanAuditor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Schema::create( 'ci_wishlists', function ( Blueprint $table ): void {
        $table->id();
    } );
    Schema::create( 'ci_loyalty_points', function ( Blueprint $table ): void {
        $table->id();
    } );
    Schema::table( 'ecommerce_products', function ( Blueprint $table ): void {
        $table->string( 'ci_badge' )->nullable();
    } );
} );

/**
 * The audit, after the uninstalled-package cache is cleared.
 *
 * @return array<int, array{package: string, reason: string, kind: string, table: string, column: string|null}>
 */
function ciAudit(): array
{
    app( SatelliteRegistry::class )->flush();

    return app( SatelliteOrphanAuditor::class )->audit();
}

it( 'reports nothing while every recorded satellite is registered and active', function (): void {
    Satellite::factory()->create( [ 'package_name' => 'acme/ecommerce-wishlists', 'owned_tables' => [ 'ci_wishlists' ] ] );
    app( SatelliteRegistry::class )->register( new SatelliteDescriptor( 'acme/ecommerce-wishlists', '1.0.0', tables: [ 'ci_wishlists' ] ) );

    expect( ciAudit() )->toBe( [] );
} );

it( 'reports the tables and columns of a satellite that is no longer registered', function (): void {
    Satellite::factory()->create( [
        'package_name'  => 'acme/ecommerce-loyalty',
        'owned_tables'  => [ 'ci_loyalty_points', 'ci_never_created' ],
        'owned_columns' => [ 'ecommerce_products' => [ 'ci_badge', 'ci_missing_column' ], 'ci_missing_table' => [ 'x' ] ],
    ] );

    expect( ciAudit() )->toBe( [
        [ 'package' => 'acme/ecommerce-loyalty', 'reason' => 'not-registered', 'kind' => 'table', 'table' => 'ci_loyalty_points', 'column' => null ],
        [ 'package' => 'acme/ecommerce-loyalty', 'reason' => 'not-registered', 'kind' => 'column', 'table' => 'ecommerce_products', 'column' => 'ci_badge' ],
    ] );
} );

it( 'reports an uninstalled satellite even while registered, unless an active one still claims the table', function (): void {
    Satellite::factory()->uninstalled()->create( [ 'package_name' => 'acme/ecommerce-wishlists', 'owned_tables' => [ 'ci_wishlists', 'ci_loyalty_points' ] ] );
    app( SatelliteRegistry::class )->register( new SatelliteDescriptor( 'acme/ecommerce-wishlists', '1.0.0', tables: [ 'ci_wishlists', 'ci_loyalty_points' ] ) );

    expect( array_column( ciAudit(), 'table' ) )->toBe( [ 'ci_wishlists', 'ci_loyalty_points' ] )
        ->and( array_unique( array_column( ciAudit(), 'reason' ) ) )->toBe( [ 'uninstalled' ] );

    app( SatelliteRegistry::class )->register( new SatelliteDescriptor( 'acme/ecommerce-loyalty', '2.0.0', tables: [ 'ci_loyalty_points' ] ) );

    expect( array_column( ciAudit(), 'table' ) )->toBe( [ 'ci_wishlists' ] );
} );

it( 'treats a column claimed by an active satellite as owned', function (): void {
    Satellite::factory()->create( [ 'package_name' => 'acme/ecommerce-old-badges', 'owned_columns' => [ 'ecommerce_products' => [ 'ci_badge' ] ] ] );
    app( SatelliteRegistry::class )->register( new SatelliteDescriptor( 'acme/ecommerce-badges', '1.0.0', columns: [ 'ecommerce_products' => [ 'ci_badge' ] ] ) );

    expect( ciAudit() )->toBe( [] );
} );

it( 'ships a no-op uninstaller for satellites with nothing long-lived to unbind', function (): void {
    $uninstaller = new NullSatelliteUninstaller();
    $descriptor  = new SatelliteDescriptor( 'acme/ecommerce-wishlists', '1.0.0', tables: [ 'ci_wishlists' ] );

    $uninstaller->uninstall( $descriptor, true );
    $uninstaller->uninstall( $descriptor, false );

    expect( $uninstaller )->toBeInstanceOf( SatelliteUninstaller::class )
        ->and( Schema::hasTable( 'ci_wishlists' ) )->toBeTrue();
} );
