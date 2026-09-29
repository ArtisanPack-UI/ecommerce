<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Testing\Verification\ReportSigner;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

$signDirs = [];

afterEach( function () use ( &$signDirs ): void {
    foreach ( $signDirs as $dir ) {
        File::deleteDirectory( $dir );
    }

    $signDirs = [];
} );

/**
 * Runs bin/ecommerce-sign-report in `$dir` with the given key.
 *
 * @return Process
 */
function runSignReport( string $dir, ?string $key ): Process
{
    $process = new Process(
        [ PHP_BINARY, dirname( __DIR__, 3 ) . '/bin/ecommerce-sign-report', 'report.json', 'report.sig' ],
        $dir,
        [ 'ECOMMERCE_VERIFY_SIGNING_KEY' => $key ?? false ],
    );

    $process->run();

    return $process;
}

function signReportTempDir( array $report ): string
{
    $dir = sys_get_temp_dir() . '/ecommerce-sign-test-' . bin2hex( random_bytes( 6 ) );
    mkdir( $dir );
    file_put_contents( $dir . '/report.json', json_encode( $report, JSON_PRETTY_PRINT ) . "\n" );

    return $dir;
}

it( 'signs a verified report without booting the application', function () use ( &$signDirs ): void {
    $signDirs[] = $dir = signReportTempDir( [ 'package' => 'acme/x', 'verified' => true ] );
    $keys       = ReportSigner::generateKeyPair();

    $process = runSignReport( $dir, $keys['secret_key'] );

    expect( $process->getExitCode() )->toBe( 0 )
        ->and( $process->getOutput() )->toContain( 'Signature written to report.sig' );

    $signature = json_decode( (string) file_get_contents( $dir . '/report.sig' ), true );

    expect( ( new ReportSigner() )->verify( (string) file_get_contents( $dir . '/report.json' ), $signature, $keys['public_key'] ) )->toBeTrue();
} );

it( 'refuses to sign a report that is not verified', function () use ( &$signDirs ): void {
    $signDirs[] = $dir = signReportTempDir( [ 'package' => 'acme/x', 'verified' => false ] );

    $process = runSignReport( $dir, ReportSigner::generateKeyPair()['secret_key'] );

    expect( $process->getExitCode() )->toBe( 1 )
        ->and( $process->getErrorOutput() )->toContain( 'not verified' )
        ->and( is_file( $dir . '/report.sig' ) )->toBeFalse();
} );

it( 'fails without a signing key or with a malformed one', function () use ( &$signDirs ): void {
    $signDirs[] = $dir = signReportTempDir( [ 'package' => 'acme/x', 'verified' => true ] );

    expect( runSignReport( $dir, null )->getExitCode() )->toBe( 1 )
        ->and( runSignReport( $dir, base64_encode( 'short' ) )->getErrorOutput() )->toContain( 'base64-encoded Ed25519' );
} );
