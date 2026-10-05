<?php

/**
 * ProcessSuiteRunner.
 *
 * Runs the satellite's contract-test classes in a subprocess inside the
 * satellite's own directory — `vendor/bin/pest` when present, otherwise
 * `vendor/bin/phpunit` — and reads the results back from `--log-junit`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Verification;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Subprocess-backed {@see SuiteRunner}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProcessSuiteRunner implements SuiteRunner
{
    /**
     * @since 1.0.0
     *
     * @param  JUnitReportParser  $parser   JUnit parser.
     * @param  int                $timeout  Seconds before the test process is killed.
     */
    public function __construct(
        private readonly JUnitReportParser $parser = new JUnitReportParser(),
        private readonly int $timeout = 900,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @param  SatelliteManifest   $manifest     Satellite under verification.
     * @param  array<int, string>  $testClasses  Concrete test class FQCNs.
     *
     * @return SuiteRunResult
     */
    public function run( SatelliteManifest $manifest, array $testClasses ): SuiteRunResult
    {
        if ( [] === $testClasses ) {
            return new SuiteRunResult();
        }

        $binary = $this->binary( $manifest->path );

        if ( null === $binary ) {
            return new SuiteRunResult( [], __( 'Neither vendor/bin/pest nor vendor/bin/phpunit exists in :path.', [ 'path' => $manifest->path ] ) );
        }

        $base  = (string) tempnam( sys_get_temp_dir(), 'ecommerce-verify-' );
        $junit = $base . '.xml';

        @unlink( $base );

        $process = new Process(
            [ PHP_BINARY, $binary, '--filter', $this->filter( $testClasses ), '--log-junit', $junit ],
            $manifest->path,
            // The signing key must never reach satellite (or dependency)
            // code: `false` removes an inherited variable from the child.
            [ 'XDEBUG_MODE' => 'off', 'ECOMMERCE_VERIFY_SIGNING_KEY' => false ],
            null,
            $this->timeout,
        );

        try {
            $process->run();
        } catch ( ProcessTimedOutException ) {
            @unlink( $junit );

            return new SuiteRunResult( [], __( 'The contract suite timed out after :seconds seconds.', [ 'seconds' => (string) $this->timeout ] ) );
        } catch ( Throwable $e ) {
            @unlink( $junit );

            return new SuiteRunResult( [], __( 'The contract suite could not be started: :reason', [ 'reason' => $e->getMessage() ] ) );
        }

        $xml = is_file( $junit ) ? (string) file_get_contents( $junit ) : '';

        if ( is_file( $junit ) ) {
            @unlink( $junit );
        }

        $classes = $this->parser->parse( $xml );

        if ( null === $classes ) {
            return new SuiteRunResult( [], __( 'The contract suite produced no JUnit report (exit code :code): :output', [
                'code'   => (string) $process->getExitCode(),
                'output' => mb_substr( trim( $process->getErrorOutput() . "\n" . $process->getOutput() ), -2000 ),
            ] ) );
        }

        return new SuiteRunResult( $classes );
    }

    /**
     * The satellite's test binary, preferring Pest.
     *
     * @since 1.0.0
     *
     * @param  string  $path  Satellite root.
     *
     * @return string|null
     */
    protected function binary( string $path ): ?string
    {
        foreach ( [ 'pest', 'phpunit' ] as $name ) {
            $candidate = $path . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $name;

            if ( is_file( $candidate ) ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * PHPUnit `--filter` regex that matches only `$testClasses`.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $testClasses  Class FQCNs.
     *
     * @return string
     */
    protected function filter( array $testClasses ): string
    {
        $alternatives = array_map( static fn ( string $class ): string => preg_quote( $class, '/' ), $testClasses );

        return '/^(' . implode( '|', $alternatives ) . ')::/';
    }
}
