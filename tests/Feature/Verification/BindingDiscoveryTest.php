<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\CartStorage;
use ArtisanPackUI\Ecommerce\Testing\Contracts\CartStorageContractTest;
use ArtisanPackUI\Ecommerce\Testing\Verification\RegistrationDiscovery;
use ArtisanPackUI\Ecommerce\Testing\Verification\SatelliteManifest;
use ArtisanPackUI\Ecommerce\Testing\Verification\SatelliteVerifier;
use ArtisanPackUI\Ecommerce\Testing\Verification\TestClassLocator;
use ArtisanPackUI\Ecommerce\Testing\Verification\VerificationReport;
use Tests\Fixtures\Verification\UnbuildableCartStorage;

require_once __DIR__ . '/helpers.php';

it( 'reads a class-string binding without building it', function (): void {
    app()->singleton( CartStorage::class, UnbuildableCartStorage::class );

    $manifest = new SatelliteManifest( __DIR__, 'acme/x', '1.0.0', [ 'Tests\\Fixtures\\Verification\\' ], [] );
    $found    = collect( ( new RegistrationDiscovery( app() ) )->discover( $manifest ) )->keyBy( 'contract' );

    expect( $found[ CartStorage::class ]->class )->toBe( UnbuildableCartStorage::class )
        ->and( $found[ CartStorage::class ]->error )->toBeNull()
        ->and( $found[ CartStorage::class ]->suite )->toBe( CartStorageContractTest::class );
} );

it( 'reports an unresolvable satellite factory binding as failed instead of dropping it', function (): void {
    app()->singleton( CartStorage::class, static function (): never {
        throw new RuntimeException( 'Redis connection refused.' );
    } );

    // This file sits under the manifest path, so the factory is the satellite's.
    $manifest = new SatelliteManifest( __DIR__, 'acme/x', '1.0.0', [ 'Acme\\Nothing\\' ], [] );
    $found    = collect( ( new RegistrationDiscovery( app() ) )->discover( $manifest ) )->keyBy( 'contract' );

    expect( $found[ CartStorage::class ]->error )->toContain( 'Redis connection refused.' );

    $report = ( new SatelliteVerifier( new RegistrationDiscovery( app() ), new TestClassLocator(), fakeSuiteRunner( [] ) ) )->verify( $manifest, true );
    $row    = collect( $report->registrations )->firstWhere( 'contract', CartStorage::class );

    expect( $row['status'] )->toBe( VerificationReport::STATUS_FAILED )
        ->and( $row['error'] )->toContain( 'Redis connection refused.' )
        ->and( $report->verified() )->toBeFalse();
} );

it( 'ignores factory bindings defined outside the satellite', function (): void {
    app()->singleton( CartStorage::class, static function (): never {
        throw new RuntimeException( 'host binding' );
    } );

    $manifest = new SatelliteManifest( sys_get_temp_dir(), 'acme/x', '1.0.0', [ 'Acme\\Nothing\\' ], [] );

    expect( ( new RegistrationDiscovery( app() ) )->discover( $manifest ) )->toBe( [] );
} );
