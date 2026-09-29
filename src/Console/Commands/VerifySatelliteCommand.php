<?php

/**
 * VerifySatelliteCommand.
 *
 * `ecommerce:verify-satellite` — discovers what a satellite registers with
 * the engine, runs the matching abstract contract suites against it, and
 * writes a JSON report (optionally Ed25519-signed) that powers the public
 * "contract-verified" badge. Parent plan §15.2.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Console\Commands;

use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\Ecommerce\Testing\Verification\ProcessSuiteRunner;
use ArtisanPackUI\Ecommerce\Testing\Verification\RegistrationDiscovery;
use ArtisanPackUI\Ecommerce\Testing\Verification\ReportSigner;
use ArtisanPackUI\Ecommerce\Testing\Verification\SatelliteManifest;
use ArtisanPackUI\Ecommerce\Testing\Verification\SatelliteVerifier;
use ArtisanPackUI\Ecommerce\Testing\Verification\SuiteRunner;
use ArtisanPackUI\Ecommerce\Testing\Verification\TestClassLocator;
use ArtisanPackUI\Ecommerce\Testing\Verification\VerificationReport;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class VerifySatelliteCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ecommerce:verify-satellite
        {--path= : Satellite root directory (defaults to the current working directory).}
        {--output= : Report path (defaults to artisanpack.ecommerce.satellites.verification.report_path, relative to --path).}
        {--package-version= : Version to record in the report (e.g. the git tag); defaults to composer.json\'s version.}
        {--namespace=* : Extra namespace prefixes to attribute to the satellite.}
        {--tests=* : Extra directories to scan for contract tests.}
        {--provider=* : Service providers to register before discovery.}
        {--no-run : Discover registrations and matching tests without running them.}
        {--allow-empty : Treat a satellite that registers nothing as verified.}
        {--sign : Sign a passing report with the configured signing key.}
        {--signature= : Signature file path (defaults to verify-report.sig next to the report).}
        {--check-signature : Verify an existing report + signature against the public key instead of running.}
        {--public-key= : Base64 Ed25519 public key for --check-signature (defaults to the configured key).}
        {--record : Store the passing report\'s sha256 on the satellite\'s ecommerce_satellites row.}';

    /**
     * @var string
     */
    protected $description = 'Run the engine contract suites against a satellite and write a (signed) verification report.';

    /**
     * @since 1.0.0
     *
     * @return int
     */
    public function handle(): int
    {
        $path   = $this->satellitePath();
        $output = $this->absolute( (string) ( $this->option( 'output' ) ?: config( 'artisanpack.ecommerce.satellites.verification.report_path', '.ecommerce-verify-report.json' ) ), $path );
        $sig    = $this->absolute( (string) ( $this->option( 'signature' ) ?: dirname( $output ) . DIRECTORY_SEPARATOR . 'verify-report.sig' ), $path );

        if ( $this->option( 'check-signature' ) ) {
            return $this->checkSignature( $output, $sig );
        }

        try {
            $manifest = SatelliteManifest::fromPath(
                $path,
                $this->option( 'package-version' ) ?: null,
                (array) $this->option( 'namespace' ),
                (array) $this->option( 'tests' ),
            );
        } catch ( InvalidArgumentException $e ) {
            $this->error( $e->getMessage() );

            return self::FAILURE;
        }

        foreach ( (array) $this->option( 'provider' ) as $provider ) {
            if ( ! class_exists( $provider ) ) {
                $this->error( __( 'Service provider :provider does not exist.', [ 'provider' => $provider ] ) );

                return self::FAILURE;
            }

            $this->laravel->register( $provider );
        }

        $run    = ! $this->option( 'no-run' );
        $report = $this->verifier()->verify( $manifest, $run, (bool) $this->option( 'allow-empty' ) );

        $this->render( $report );

        if ( ! is_dir( dirname( $output ) ) ) {
            mkdir( dirname( $output ), 0755, true );
        }

        file_put_contents( $output, $report->toJson() );

        $this->line( __( 'Report written to :path (sha256 :hash).', [ 'path' => $output, 'hash' => $report->hash() ] ) );

        if ( ! $run ) {
            $summary = $report->summary();

            return 0 === $summary[ VerificationReport::STATUS_MISSING ] && 0 === $summary[ VerificationReport::STATUS_FAILED ] ? self::SUCCESS : self::FAILURE;
        }

        if ( ! $report->verified() ) {
            $this->error( __( ':package is not contract-verified.', [ 'package' => $report->package ] ) );

            return self::FAILURE;
        }

        $this->info( __( ':package :version is contract-verified.', [ 'package' => $report->package, 'version' => $report->version ] ) );

        if ( $this->option( 'record' ) && self::SUCCESS !== $this->record( $report ) ) {
            return self::FAILURE;
        }

        if ( $this->option( 'sign' ) ) {
            return $this->sign( $report, $sig );
        }

        return self::SUCCESS;
    }

    /**
     * Stores the report hash on the satellite's registry row so the admin
     * surfaces (and the public badge) can tell which report is current.
     *
     * @since 1.0.0
     *
     * @param  VerificationReport  $report  Passing report.
     *
     * @return int
     */
    protected function record( VerificationReport $report ): int
    {
        try {
            $this->laravel->make( SatelliteRegistry::class )->recordVerification( $report->package, $report->hash() );
        } catch ( RuntimeException $e ) {
            $this->error( __( 'Could not record the verification for :package: :reason', [ 'package' => $report->package, 'reason' => $e->getMessage() ] ) );

            return self::FAILURE;
        }

        $this->line( __( 'Verification recorded for :package.', [ 'package' => $report->package ] ) );

        return self::SUCCESS;
    }

    /**
     * Signs `$report` and writes the signature file.
     *
     * @since 1.0.0
     *
     * @param  VerificationReport  $report  Passing report.
     * @param  string              $path    Signature file path.
     *
     * @return int
     */
    protected function sign( VerificationReport $report, string $path ): int
    {
        $key = (string) config( 'artisanpack.ecommerce.satellites.verification.signing_key', '' );

        if ( '' === $key ) {
            $this->error( __( 'No signing key configured. Set ECOMMERCE_VERIFY_SIGNING_KEY.' ) );

            return self::FAILURE;
        }

        $signer = new ReportSigner();

        try {
            $signature = $signer->sign( $report->toJson(), $key );
        } catch ( InvalidArgumentException $e ) {
            $this->error( $e->getMessage() );

            return self::FAILURE;
        }

        file_put_contents( $path, $signer->encode( $signature ) );

        $this->info( __( 'Signature written to :path (key :key).', [ 'path' => $path, 'key' => $signature['key_id'] ] ) );

        return self::SUCCESS;
    }

    /**
     * Checks an existing report against its signature file.
     *
     * @since 1.0.0
     *
     * @param  string  $report     Report path.
     * @param  string  $signature  Signature path.
     *
     * @return int
     */
    protected function checkSignature( string $report, string $signature ): int
    {
        $publicKey = (string) ( $this->option( 'public-key' ) ?: config( 'artisanpack.ecommerce.satellites.verification.public_key', '' ) );

        if ( '' === $publicKey ) {
            $this->error( __( 'No public key given. Pass --public-key or set ECOMMERCE_VERIFY_PUBLIC_KEY.' ) );

            return self::FAILURE;
        }

        if ( ! is_file( $report ) || ! is_file( $signature ) ) {
            $this->error( __( 'Report or signature file not found.' ) );

            return self::FAILURE;
        }

        $document = json_decode( (string) file_get_contents( $signature ), true );
        $bytes    = (string) file_get_contents( $report );

        if ( ! is_array( $document ) || ! ( new ReportSigner() )->verify( $bytes, $document, $publicKey ) ) {
            $this->error( __( 'Signature is NOT valid for this report.' ) );

            return self::FAILURE;
        }

        $data = json_decode( $bytes, true );

        if ( true !== ( $data['verified'] ?? null ) ) {
            $this->error( __( 'Signature is valid, but the report does not mark the satellite as verified.' ) );

            return self::FAILURE;
        }

        $this->info( __( 'Signature is valid: :package :version is contract-verified.', [
            'package' => (string) ( $data['package'] ?? '' ),
            'version' => (string) ( $data['version'] ?? '' ),
        ] ) );

        return self::SUCCESS;
    }

    /**
     * Prints the per-registration table and summary.
     *
     * @since 1.0.0
     *
     * @param  VerificationReport  $report  Report.
     *
     * @return void
     */
    protected function render( VerificationReport $report ): void
    {
        $this->line( __( 'Verifying :package :version against engine :engine.', [
            'package' => $report->package,
            'version' => $report->version,
            'engine'  => $report->engineVersion,
        ] ) );

        if ( [] === $report->registrations ) {
            $this->warn( __( 'The satellite registers no engine contract implementations.' ) );
        } else {
            $this->table(
                [ __( 'Contract' ), __( 'Key' ), __( 'Class' ), __( 'Status' ), __( 'Tests' ), __( 'Failures' ) ],
                array_map( static fn ( array $row ): array => [
                    class_basename( $row['contract'] ),
                    $row['key'],
                    $row['class'],
                    $row['status'],
                    $row['tests'],
                    $row['failures'],
                ], $report->registrations ),
            );
        }

        if ( null !== $report->runnerError ) {
            $this->error( $report->runnerError );
        }

        $summary = $report->summary();

        $this->line( __( ':total registration(s): :passed passed, :failed failed, :missing missing a contract test, :noSuite without a shared suite.', [
            'total'   => (string) $summary['registrations'],
            'passed'  => (string) $summary[ VerificationReport::STATUS_PASSED ],
            'failed'  => (string) $summary[ VerificationReport::STATUS_FAILED ],
            'missing' => (string) $summary[ VerificationReport::STATUS_MISSING ],
            'noSuite' => (string) $summary[ VerificationReport::STATUS_NO_SUITE ],
        ] ) );
    }

    /**
     * Builds the verifier, preferring a container-bound {@see SuiteRunner}.
     *
     * @since 1.0.0
     *
     * @return SatelliteVerifier
     */
    protected function verifier(): SatelliteVerifier
    {
        $runner = $this->laravel->bound( SuiteRunner::class )
            ? $this->laravel->make( SuiteRunner::class )
            : new ProcessSuiteRunner();

        return new SatelliteVerifier(
            new RegistrationDiscovery( $this->laravel ),
            new TestClassLocator(),
            $runner,
        );
    }

    /**
     * Satellite root: `--path`, else the working directory (under Testbench
     * `base_path()` is the skeleton app, not the satellite).
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function satellitePath(): string
    {
        $path = (string) ( $this->option( 'path' ) ?: getcwd() );

        return rtrim( false === realpath( $path ) ? $path : (string) realpath( $path ), DIRECTORY_SEPARATOR );
    }

    /**
     * Resolves `$path` against `$root` unless it is already absolute.
     *
     * @since 1.0.0
     *
     * @param  string  $path  Path.
     * @param  string  $root  Base directory.
     *
     * @return string
     */
    protected function absolute( string $path, string $root ): string
    {
        return str_starts_with( $path, DIRECTORY_SEPARATOR ) ? $path : $root . DIRECTORY_SEPARATOR . $path;
    }
}
