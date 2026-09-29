<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Satellite;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\Ecommerce\Testing\Verification\SuiteRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

require_once __DIR__ . '/helpers.php';

uses( RefreshDatabase::class );

$recordDirs = [];

afterEach( function () use ( &$recordDirs ): void {
    foreach ( $recordDirs as $dir ) {
        File::deleteDirectory( $dir );
    }

    $recordDirs = [];
} );

it( 'records the passing report hash on the satellite row with --record', function () use ( &$recordDirs ): void {
    $recordDirs[] = $dir = verificationTempDir( taxSatelliteFiles() );
    app()->instance( SuiteRunner::class, fakeSuiteRunner( [ 'Acme\\Tests\\ManualTaxProviderTest' => [ 'tests' => 9, 'assertions' => 20, 'failures' => 0, 'skipped' => 0 ] ] ) );
    app( SatelliteRegistry::class )->register( [ 'package_name' => 'acme/ecommerce-tax-fixture', 'version' => '1.2.3' ] );

    $this->artisan( 'ecommerce:verify-satellite', [ '--path' => $dir, '--record' => true ] )
        ->expectsOutputToContain( 'Verification recorded for acme/ecommerce-tax-fixture.' )
        ->assertSuccessful();

    $hash = hash_file( 'sha256', $dir . '/.ecommerce-verify-report.json' );

    expect( Satellite::query()->where( 'package_name', 'acme/ecommerce-tax-fixture' )->value( 'verified_report_hash' ) )->toBe( $hash );
} );

it( 'fails with --record when the satellite is not registered', function () use ( &$recordDirs ): void {
    $recordDirs[] = $dir = verificationTempDir( taxSatelliteFiles() );
    app()->instance( SuiteRunner::class, fakeSuiteRunner( [ 'Acme\\Tests\\ManualTaxProviderTest' => [ 'tests' => 9, 'assertions' => 20, 'failures' => 0, 'skipped' => 0 ] ] ) );

    $this->artisan( 'ecommerce:verify-satellite', [ '--path' => $dir, '--record' => true ] )
        ->expectsOutputToContain( 'Could not record the verification' )
        ->assertFailed();
} );
