<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Testing\Verification\ReportSigner;
use ArtisanPackUI\Ecommerce\Testing\Verification\SuiteRunner;
use Illuminate\Support\Facades\File;

require_once __DIR__ . '/helpers.php';

$dirs = [];

afterEach( function () use ( &$dirs ): void {
    foreach ( $dirs as $dir ) {
        File::deleteDirectory( $dir );
    }

    $dirs = [];
} );

function passingRunner(): SuiteRunner
{
    return fakeSuiteRunner( [ 'Acme\\Tests\\ManualTaxProviderTest' => [ 'tests' => 9, 'assertions' => 20, 'failures' => 0, 'skipped' => 0 ] ] );
}

it( 'writes a verified report and exits successfully', function () use ( &$dirs ): void {
    $dirs[] = $dir = verificationTempDir( taxSatelliteFiles() );
    app()->instance( SuiteRunner::class, passingRunner() );

    $this->artisan( 'ecommerce:verify-satellite', [ '--path' => $dir, '--package-version' => 'v1.2.3' ] )
        ->expectsOutputToContain( 'acme/ecommerce-tax-fixture v1.2.3 is contract-verified.' )
        ->assertSuccessful();

    $report = json_decode( (string) file_get_contents( $dir . '/.ecommerce-verify-report.json' ), true );

    expect( $report['verified'] )->toBeTrue()
        ->and( $report['package'] )->toBe( 'acme/ecommerce-tax-fixture' )
        ->and( $report['version'] )->toBe( 'v1.2.3' )
        ->and( $report['registrations'][0]['key'] )->toBe( 'manual' )
        ->and( $report['allow_empty'] )->toBeFalse()
        ->and( $report['namespaces'] )->not->toBeEmpty();
} );

it( 'fails when a registration has no contract test', function () use ( &$dirs ): void {
    $dirs[] = $dir = verificationTempDir( taxSatelliteFiles( false ) );
    app()->instance( SuiteRunner::class, passingRunner() );

    $this->artisan( 'ecommerce:verify-satellite', [ '--path' => $dir ] )
        ->expectsOutputToContain( 'is not contract-verified' )
        ->assertFailed();

    $this->artisan( 'ecommerce:verify-satellite', [ '--path' => $dir, '--no-run' => true ] )->assertFailed();
} );

it( 'fails when the suite fails', function () use ( &$dirs ): void {
    $dirs[] = $dir = verificationTempDir( taxSatelliteFiles() );
    app()->instance( SuiteRunner::class, fakeSuiteRunner( [ 'Acme\\Tests\\ManualTaxProviderTest' => [ 'tests' => 9, 'assertions' => 20, 'failures' => 1, 'skipped' => 0 ] ] ) );

    $this->artisan( 'ecommerce:verify-satellite', [ '--path' => $dir ] )->assertFailed();
} );

it( 'runs discovery only with --no-run', function () use ( &$dirs ): void {
    $dirs[] = $dir = verificationTempDir( taxSatelliteFiles() );
    $runner = passingRunner();
    app()->instance( SuiteRunner::class, $runner );

    $this->artisan( 'ecommerce:verify-satellite', [ '--path' => $dir, '--no-run' => true, '--output' => 'build/report.json' ] )
        ->assertSuccessful();

    expect( $runner->calls )->toBe( [] )
        ->and( is_file( $dir . '/build/report.json' ) )->toBeTrue();
} );

it( 'signs a passing report and checks the signature', function () use ( &$dirs ): void {
    $dirs[] = $dir = verificationTempDir( taxSatelliteFiles() );
    $keys   = ReportSigner::generateKeyPair();
    app()->instance( SuiteRunner::class, passingRunner() );
    config()->set( 'artisanpack.ecommerce.satellites.verification.signing_key', $keys['secret_key'] );

    $this->artisan( 'ecommerce:verify-satellite', [ '--path' => $dir, '--sign' => true ] )->assertSuccessful();

    expect( is_file( $dir . '/verify-report.sig' ) )->toBeTrue();

    $this->artisan( 'ecommerce:verify-satellite', [ '--path' => $dir, '--check-signature' => true, '--public-key' => $keys['public_key'] ] )
        ->expectsOutputToContain( 'Signature is valid' )
        ->assertSuccessful();

    file_put_contents( $dir . '/.ecommerce-verify-report.json', str_replace( '"passed"', '"failed"', (string) file_get_contents( $dir . '/.ecommerce-verify-report.json' ) ) );

    $this->artisan( 'ecommerce:verify-satellite', [ '--path' => $dir, '--check-signature' => true, '--public-key' => $keys['public_key'] ] )
        ->expectsOutputToContain( 'NOT valid' )
        ->assertFailed();
} );

it( 'refuses to sign without a configured key', function () use ( &$dirs ): void {
    $dirs[] = $dir = verificationTempDir( taxSatelliteFiles() );
    app()->instance( SuiteRunner::class, passingRunner() );
    config()->set( 'artisanpack.ecommerce.satellites.verification.signing_key', null );

    $this->artisan( 'ecommerce:verify-satellite', [ '--path' => $dir, '--sign' => true ] )
        ->expectsOutputToContain( 'No signing key configured' )
        ->assertFailed();
} );

it( 'fails cleanly when the path has no composer.json', function () use ( &$dirs ): void {
    $dirs[] = $dir = verificationTempDir();

    $this->artisan( 'ecommerce:verify-satellite', [ '--path' => $dir ] )
        ->expectsOutputToContain( 'No composer.json found' )
        ->assertFailed();
} );
