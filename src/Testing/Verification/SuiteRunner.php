<?php

/**
 * SuiteRunner.
 *
 * Runs a satellite's contract-test classes and reports per-class results.
 * {@see ProcessSuiteRunner} is the real implementation; tests bind a fake
 * to the container so no subprocess is spawned.
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

/**
 * Contract for running contract-test classes.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface SuiteRunner
{
    /**
     * Runs `$testClasses` inside the satellite and returns their results.
     *
     * @since 1.0.0
     *
     * @param  SatelliteManifest   $manifest     Satellite under verification.
     * @param  array<int, string>  $testClasses  Concrete test class FQCNs.
     *
     * @return SuiteRunResult
     */
    public function run( SatelliteManifest $manifest, array $testClasses ): SuiteRunResult;
}
