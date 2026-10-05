<?php

/**
 * SatelliteVerifier.
 *
 * Orchestrates one verification run: discover the satellite's
 * registrations, match them to its contract-test subclasses, run those
 * tests, and assemble a {@see VerificationReport}. The console command is
 * a thin shell around this class, so a future dev-only
 * `artisanpack-ui/ecommerce-contract-tests` package can wrap it without
 * duplicating logic. Parent plan §15.2.
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

use ArtisanPackUI\Ecommerce\Ecommerce;
use Illuminate\Support\Carbon;

/**
 * Runs satellite contract verification.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SatelliteVerifier
{
    /**
     * @since 1.0.0
     *
     * @param  RegistrationDiscovery  $discovery  Registration discovery.
     * @param  TestClassLocator       $locator    Contract-test locator.
     * @param  SuiteRunner            $runner     Contract-suite runner.
     */
    public function __construct(
        private readonly RegistrationDiscovery $discovery,
        private readonly TestClassLocator $locator,
        private readonly SuiteRunner $runner,
    ) {
    }

    /**
     * Verifies the satellite described by `$manifest`.
     *
     * @since 1.0.0
     *
     * @param  SatelliteManifest  $manifest    Satellite under verification.
     * @param  bool               $run         Whether to execute the suites (false = discovery only).
     * @param  bool               $allowEmpty  Whether zero registrations counts as verified.
     *
     * @return VerificationReport
     */
    public function verify( SatelliteManifest $manifest, bool $run = true, bool $allowEmpty = false ): VerificationReport
    {
        $registrations = $this->discovery->discover( $manifest );
        $located       = $this->locator->locate( $manifest->testPaths );

        $perContract = [];

        foreach ( $registrations as $registration ) {
            $perContract[ $registration->contract ] = ( $perContract[ $registration->contract ] ?? 0 ) + 1;
        }

        $matched = [];

        foreach ( $registrations as $index => $registration ) {
            $matched[ $index ] = $this->locator->classesFor( $registration, $located, $perContract[ $registration->contract ] );
        }

        $result = new SuiteRunResult();

        if ( $run ) {
            $toRun = array_values( array_unique( array_merge( [], ...array_values( $matched ) ) ) );
            sort( $toRun );

            $result = $this->runner->run( $manifest, $toRun );
        }

        $rows = [];

        foreach ( $registrations as $index => $registration ) {
            $classes = $matched[ $index ];
            $totals  = $result->totalsFor( $classes );

            $rows[] = [
                'contract'     => $registration->contract,
                'key'          => $registration->key,
                'class'        => $registration->class,
                'suite'        => $registration->suite,
                'test_classes' => $classes,
                'status'       => $this->status( $registration, $classes, $totals, $run, $result ),
                'tests'        => $totals['tests'],
                'assertions'   => $totals['assertions'],
                'failures'     => $totals['failures'],
                'skipped'      => $totals['skipped'],
                'error'        => $registration->error,
            ];
        }

        return new VerificationReport(
            $manifest->package,
            $manifest->version,
            $this->engineVersion(),
            Carbon::now( 'UTC' )->toIso8601ZuluString(),
            PHP_VERSION,
            $rows,
            $run,
            $allowEmpty,
            $result->error,
            $manifest->namespaces,
        );
    }

    /**
     * Status for one registration.
     *
     * A suite that ran zero tests counts as failed: an empty filter match
     * must never read as a pass.
     *
     * @since 1.0.0
     *
     * @param  Registration                                                $registration  Registration.
     * @param  array<int, string>                                          $classes       Matched test classes.
     * @param  array{tests: int, assertions: int, failures: int, skipped: int} $totals    Aggregated results.
     * @param  bool                                                        $run           Whether suites ran.
     * @param  SuiteRunResult                                              $result        Run result.
     *
     * @return string
     */
    protected function status( Registration $registration, array $classes, array $totals, bool $run, SuiteRunResult $result ): string
    {
        if ( null !== $registration->error ) {
            return VerificationReport::STATUS_FAILED;
        }

        if ( null === $registration->suite ) {
            return VerificationReport::STATUS_NO_SUITE;
        }

        if ( [] === $classes ) {
            return VerificationReport::STATUS_MISSING;
        }

        if ( ! $run ) {
            return VerificationReport::STATUS_SKIPPED;
        }

        if ( null !== $result->error || $totals['failures'] > 0 || 0 === $totals['tests'] - $totals['skipped'] ) {
            return VerificationReport::STATUS_FAILED;
        }

        return VerificationReport::STATUS_PASSED;
    }

    /**
     * Installed engine version.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function engineVersion(): string
    {
        return Ecommerce::version();
    }
}
