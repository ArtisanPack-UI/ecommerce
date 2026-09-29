<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\CartStorage;
use ArtisanPackUI\Ecommerce\Contracts\CurrencyRateProvider;
use ArtisanPackUI\Ecommerce\Contracts\OrderNumberGenerator;
use ArtisanPackUI\Ecommerce\Contracts\TaxProvider;
use ArtisanPackUI\Ecommerce\CurrencyRates\ConfigRateProvider;
use ArtisanPackUI\Ecommerce\Services\DatabaseCartStorage;
use ArtisanPackUI\Ecommerce\Tax\ManualTaxProvider;
use ArtisanPackUI\Ecommerce\Testing\Contracts\CartStorageContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionActionContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\TaxProviderContractTest;
use ArtisanPackUI\Ecommerce\Testing\Verification\JUnitReportParser;
use ArtisanPackUI\Ecommerce\Testing\Verification\ProcessSuiteRunner;
use ArtisanPackUI\Ecommerce\Testing\Verification\Registration;
use ArtisanPackUI\Ecommerce\Testing\Verification\RegistrationDiscovery;
use ArtisanPackUI\Ecommerce\Testing\Verification\SatelliteManifest;
use ArtisanPackUI\Ecommerce\Testing\Verification\SatelliteVerifier;
use ArtisanPackUI\Ecommerce\Testing\Verification\TestClassLocator;
use ArtisanPackUI\Ecommerce\Testing\Verification\VerificationReport;
use Illuminate\Support\Facades\File;

require_once __DIR__ . '/helpers.php';

$dirs = [];

afterEach( function () use ( &$dirs ): void {
    foreach ( $dirs as $dir ) {
        File::deleteDirectory( $dir );
    }

    $dirs = [];
} );

describe( 'SatelliteManifest', function () use ( &$dirs ): void {
    it( 'reads name, version, namespaces, and test paths from composer.json', function () use ( &$dirs ): void {
        $dirs[] = $dir = verificationTempDir( taxSatelliteFiles() );

        $manifest = SatelliteManifest::fromPath( $dir );

        expect( $manifest->package )->toBe( 'acme/ecommerce-tax-fixture' )
            ->and( $manifest->version )->toBe( '1.2.3' )
            ->and( $manifest->namespaces )->toBe( [ 'ArtisanPackUI\\Ecommerce\\Tax\\' ] )
            ->and( $manifest->testPaths )->toBe( [ $dir . DIRECTORY_SEPARATOR . 'tests' ] )
            ->and( $manifest->owns( ManualTaxProvider::class ) )->toBeTrue()
            ->and( $manifest->owns( ConfigRateProvider::class ) )->toBeFalse();
    } );

    it( 'prefers an explicit version and merges extra namespaces', function () use ( &$dirs ): void {
        $dirs[] = $dir = verificationTempDir( taxSatelliteFiles() );

        $manifest = SatelliteManifest::fromPath( $dir, 'v2.0.0', [ 'Acme\\Extra' ] );

        expect( $manifest->version )->toBe( 'v2.0.0' )
            ->and( $manifest->namespaces )->toContain( 'Acme\\Extra\\' );
    } );

    it( 'throws when composer.json is missing', function () use ( &$dirs ): void {
        $dirs[] = $dir = verificationTempDir();

        SatelliteManifest::fromPath( $dir );
    } )->throws( InvalidArgumentException::class );
} );

describe( 'RegistrationDiscovery', function (): void {
    it( 'attributes registry entries to the satellite by namespace and maps suites', function (): void {
        $manifest = new SatelliteManifest( '/tmp', 'acme/x', '1.0.0', [ 'ArtisanPackUI\\Ecommerce\\Tax\\', 'ArtisanPackUI\\Ecommerce\\CurrencyRates\\' ], [] );

        $found = ( new RegistrationDiscovery( app() ) )->discover( $manifest );
        $byKey = collect( $found )->keyBy( 'key' );

        expect( $byKey->keys()->all() )->toEqualCanonicalizing( [ 'config', 'frankfurter', 'manual' ] )
            ->and( $byKey['manual']->contract )->toBe( TaxProvider::class )
            ->and( $byKey['manual']->class )->toBe( ManualTaxProvider::class )
            ->and( $byKey['manual']->suite )->toBe( TaxProviderContractTest::class )
            ->and( $byKey['config']->contract )->toBe( CurrencyRateProvider::class )
            ->and( $byKey['config']->suite )->toBeNull();
    } );

    it( 'picks up single-implementation container bindings', function (): void {
        $manifest = new SatelliteManifest( '/tmp', 'acme/x', '1.0.0', [ 'ArtisanPackUI\\Ecommerce\\Services\\' ], [] );

        $found = collect( ( new RegistrationDiscovery( app() ) )->discover( $manifest ) )->keyBy( 'contract' );

        expect( $found[ CartStorage::class ]->class )->toBe( DatabaseCartStorage::class )
            ->and( $found[ CartStorage::class ]->suite )->toBe( CartStorageContractTest::class )
            ->and( $found->has( OrderNumberGenerator::class ) )->toBeTrue();
    } );

    it( 'finds nothing for a namespace the satellite does not register into', function (): void {
        $manifest = new SatelliteManifest( '/tmp', 'acme/x', '1.0.0', [ 'Acme\\Nothing\\' ], [] );

        expect( ( new RegistrationDiscovery( app() ) )->discover( $manifest ) )->toBe( [] );
    } );
} );

describe( 'TestClassLocator', function () use ( &$dirs ): void {
    it( 'finds direct, aliased, and indirect suite subclasses and skips abstract and anonymous classes', function () use ( &$dirs ): void {
        $dirs[] = $dir = verificationTempDir( [
            'tests/Direct.php'   => "<?php\nnamespace Acme\\Tests;\nuse ArtisanPackUI\\Ecommerce\\Testing\\Contracts\\TaxProviderContractTest;\nclass Direct extends TaxProviderContractTest {}\n",
            'tests/Aliased.php'  => "<?php\nnamespace Acme\\Tests;\nuse ArtisanPackUI\\Ecommerce\\Testing\\Contracts\\TaxProviderContractTest as Suite;\nfinal class Aliased extends Suite {}\n",
            'tests/Base.php'     => "<?php\nnamespace Acme\\Tests;\nabstract class Base extends \\ArtisanPackUI\\Ecommerce\\Testing\\Contracts\\PromotionActionContractTest {}\n",
            'tests/Indirect.php' => "<?php\nnamespace Acme\\Tests;\nclass Indirect extends Base {}\n",
            'tests/Anon.php'     => "<?php\nnamespace Acme\\Tests;\n\$x = new class extends \\ArtisanPackUI\\Ecommerce\\Testing\\Contracts\\TaxProviderContractTest {};\nit( 'x', function () use ( \$x ) {} );\n",
            'tests/Other.php'    => "<?php\nnamespace Acme\\Tests;\nclass Other extends \\PHPUnit\\Framework\\TestCase {}\n",
        ] );

        $located = ( new TestClassLocator() )->locate( [ $dir . '/tests' ] );

        expect( array_column( $located[ TaxProviderContractTest::class ], 'class' ) )->toEqualCanonicalizing( [ 'Acme\\Tests\\Direct', 'Acme\\Tests\\Aliased' ] )
            ->and( array_column( $located[ PromotionActionContractTest::class ], 'class' ) )->toBe( [ 'Acme\\Tests\\Indirect' ] )
            ->and( array_keys( $located ) )->toHaveCount( 2 );
    } );

    it( 'matches tests to implementations by class, using keys and any subclass only for single implementations', function (): void {
        $located = [
            TaxProviderContractTest::class => [
                [ 'class' => 'Acme\\Tests\\AlphaTest', 'source' => 'new AlphaTax()' ],
                [ 'class' => 'Acme\\Tests\\BetaTest', 'source' => 'use Acme\\Tax\\BetaTax;' ],
                [ 'class' => 'Acme\\Tests\\DeltaTest', 'source' => "\$registry->get( 'delta' )" ],
            ],
        ];

        $locator = new TestClassLocator();
        $alpha   = new Registration( TaxProvider::class, 'alpha', 'Acme\\Tax\\AlphaTax', TaxProviderContractTest::class );
        $gamma   = new Registration( TaxProvider::class, 'gamma', 'Acme\\Tax\\GammaTax', TaxProviderContractTest::class );
        $delta   = new Registration( TaxProvider::class, 'delta', 'Acme\\Tax\\DeltaTax', TaxProviderContractTest::class );

        expect( $locator->classesFor( $alpha, $located, 3 ) )->toBe( [ 'Acme\\Tests\\AlphaTest' ] )
            ->and( $locator->classesFor( $gamma, $located, 3 ) )->toBe( [] )
            ->and( $locator->classesFor( $delta, $located, 3 ) )->toBe( [] )
            ->and( $locator->classesFor( $delta, $located, 1 ) )->toBe( [ 'Acme\\Tests\\DeltaTest' ] )
            ->and( $locator->classesFor( $gamma, $located, 1 ) )->toBe( [ 'Acme\\Tests\\AlphaTest', 'Acme\\Tests\\BetaTest', 'Acme\\Tests\\DeltaTest' ] );
    } );
} );

describe( 'JUnitReportParser', function (): void {
    it( 'aggregates per-class tests, assertions, failures, and skips', function (): void {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Acme\Tests\AlphaTest" tests="3">
    <testcase name="a" class="Acme\Tests\AlphaTest" assertions="2"/>
    <testcase name="b" class="Acme\Tests\AlphaTest" assertions="1"><failure>boom</failure></testcase>
    <testcase name="c" classname="Acme.Tests.AlphaTest" assertions="0"><skipped/></testcase>
  </testsuite>
  <testsuite name="Acme\Tests\BetaTest" tests="1">
    <testcase name="d" class="Acme\Tests\BetaTest" assertions="4"><error>err</error></testcase>
  </testsuite>
</testsuites>
XML;

        expect( ( new JUnitReportParser() )->parse( $xml ) )->toBe( [
            'Acme\\Tests\\AlphaTest' => [ 'tests' => 3, 'assertions' => 3, 'failures' => 1, 'skipped' => 1 ],
            'Acme\\Tests\\BetaTest'  => [ 'tests' => 1, 'assertions' => 4, 'failures' => 1, 'skipped' => 0 ],
        ] );
    } );

    it( 'returns null for empty or malformed documents', function (): void {
        $parser = new JUnitReportParser();

        expect( $parser->parse( '' ) )->toBeNull()
            ->and( $parser->parse( '<not-xml' ) )->toBeNull();
    } );
} );

describe( 'SatelliteVerifier', function () use ( &$dirs ): void {
    it( 'reports passed, missing, and no-suite registrations', function () use ( &$dirs ): void {
        $dirs[] = $dir = verificationTempDir( taxSatelliteFiles() );

        $manifest = SatelliteManifest::fromPath( $dir, null, [ 'ArtisanPackUI\\Ecommerce\\CurrencyRates\\', 'ArtisanPackUI\\Ecommerce\\Services\\' ] );
        $runner   = fakeSuiteRunner( [ 'Acme\\Tests\\ManualTaxProviderTest' => [ 'tests' => 9, 'assertions' => 20, 'failures' => 0, 'skipped' => 0 ] ] );

        $report   = ( new SatelliteVerifier( new RegistrationDiscovery( app() ), new TestClassLocator(), $runner ) )->verify( $manifest );
        $statuses = collect( $report->registrations )->pluck( 'status', 'key' );

        expect( $statuses['manual'] )->toBe( VerificationReport::STATUS_PASSED )
            ->and( $statuses['config'] )->toBe( VerificationReport::STATUS_NO_SUITE )
            ->and( $statuses['CartStorage'] )->toBe( VerificationReport::STATUS_MISSING )
            ->and( $report->verified() )->toBeFalse()
            ->and( $runner->calls )->toBe( [ [ 'Acme\\Tests\\ManualTaxProviderTest' ] ] );
    } );

    it( 'verifies a satellite whose every suite passes', function () use ( &$dirs ): void {
        $dirs[] = $dir = verificationTempDir( taxSatelliteFiles() );

        $runner = fakeSuiteRunner( [ 'Acme\\Tests\\ManualTaxProviderTest' => [ 'tests' => 9, 'assertions' => 20, 'failures' => 0, 'skipped' => 0 ] ] );
        $report = ( new SatelliteVerifier( new RegistrationDiscovery( app() ), new TestClassLocator(), $runner ) )->verify( SatelliteManifest::fromPath( $dir ) );

        expect( $report->verified() )->toBeTrue()
            ->and( $report->summary() )->toMatchArray( [ 'registrations' => 1, 'passed' => 1, 'failed' => 0, 'missing' => 0 ] );
    } );

    it( 'fails on test failures, on zero executed tests, and on runner errors', function ( array $classes, ?string $error ) use ( &$dirs ): void {
        $dirs[] = $dir = verificationTempDir( taxSatelliteFiles() );

        $report = ( new SatelliteVerifier( new RegistrationDiscovery( app() ), new TestClassLocator(), fakeSuiteRunner( $classes, $error ) ) )
            ->verify( SatelliteManifest::fromPath( $dir ) );

        expect( $report->registrations[0]['status'] )->toBe( VerificationReport::STATUS_FAILED )
            ->and( $report->verified() )->toBeFalse();
    } )->with( [
        'failing test' => [ [ 'Acme\\Tests\\ManualTaxProviderTest' => [ 'tests' => 9, 'assertions' => 20, 'failures' => 2, 'skipped' => 0 ] ], null ],
        'nothing ran'  => [ [], null ],
        'all skipped'  => [ [ 'Acme\\Tests\\ManualTaxProviderTest' => [ 'tests' => 2, 'assertions' => 0, 'failures' => 0, 'skipped' => 2 ] ], null ],
        'runner error' => [ [ 'Acme\\Tests\\ManualTaxProviderTest' => [ 'tests' => 9, 'assertions' => 20, 'failures' => 0, 'skipped' => 0 ] ], 'no binary' ],
    ] );

    it( 'skips the runner and marks matched suites not-run in discovery mode', function () use ( &$dirs ): void {
        $dirs[] = $dir = verificationTempDir( taxSatelliteFiles() );
        $runner = fakeSuiteRunner();

        $report = ( new SatelliteVerifier( new RegistrationDiscovery( app() ), new TestClassLocator(), $runner ) )
            ->verify( SatelliteManifest::fromPath( $dir ), false );

        expect( $report->registrations[0]['status'] )->toBe( VerificationReport::STATUS_SKIPPED )
            ->and( $report->verified() )->toBeFalse()
            ->and( $runner->calls )->toBe( [] );
    } );

    it( 'treats an empty satellite as unverified unless allowed', function (): void {
        $manifest = new SatelliteManifest( '/tmp', 'acme/ui', '1.0.0', [ 'Acme\\Ui\\' ], [] );
        $verifier = new SatelliteVerifier( new RegistrationDiscovery( app() ), new TestClassLocator(), fakeSuiteRunner() );

        expect( $verifier->verify( $manifest )->verified() )->toBeFalse()
            ->and( $verifier->verify( $manifest, true, true )->verified() )->toBeTrue();
    } );
} );

describe( 'VerificationReport', function (): void {
    it( 'serializes the documented shape and hashes the exact bytes', function (): void {
        $report = new VerificationReport( 'acme/x', '1.0.0', '1.0.0', '2026-09-29T00:00:00Z', '8.4.0', [
            [ 'contract' => TaxProvider::class, 'key' => 'manual', 'class' => ManualTaxProvider::class, 'suite' => TaxProviderContractTest::class, 'test_classes' => [ 'T' ], 'status' => 'passed', 'tests' => 1, 'assertions' => 1, 'failures' => 0, 'skipped' => 0 ],
        ] );

        $decoded = json_decode( $report->toJson(), true );

        expect( array_keys( $decoded ) )->toBe( [ 'schema', 'package', 'version', 'engine_version', 'generated_at', 'php_version', 'namespaces', 'ran', 'allow_empty', 'runner_error', 'registrations', 'summary', 'verified' ] )
            ->and( $decoded['schema'] )->toBe( VerificationReport::SCHEMA_VERSION )
            ->and( $decoded['verified'] )->toBeTrue()
            ->and( array_keys( $decoded['registrations'][0] ) )->toBe( [ 'contract', 'key', 'class', 'suite', 'test_classes', 'status', 'tests', 'assertions', 'failures', 'skipped' ] )
            ->and( $report->hash() )->toBe( hash( 'sha256', $report->toJson() ) );
    } );
} );

describe( 'ProcessSuiteRunner', function () use ( &$dirs ): void {
    it( 'turns a timed-out suite into a runner error instead of crashing', function () use ( &$dirs ): void {
        $dirs[]   = $dir = verificationTempDir( [ 'vendor/bin/pest' => "<?php\nsleep( 10 );\n" ] );
        $manifest = new SatelliteManifest( $dir, 'acme/x', '1.0.0', [ 'Acme\\' ], [] );

        $result = ( new ProcessSuiteRunner( new JUnitReportParser(), 1 ) )->run( $manifest, [ 'Acme\\Tests\\SlowTest' ] );

        expect( $result->error )->toBe( 'The contract suite timed out after 1 seconds.' );
    } );
} );
