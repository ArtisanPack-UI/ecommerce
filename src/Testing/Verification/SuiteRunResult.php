<?php

/**
 * SuiteRunResult.
 *
 * Per-class outcome of a contract-suite run.
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
 * Results of one {@see SuiteRunner::run()} call.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class SuiteRunResult
{
    /**
     * @since 1.0.0
     *
     * @param  array<string, array{tests: int, assertions: int, failures: int, skipped: int}>  $classes  Results keyed by test class FQCN.
     * @param  string|null                                                                    $error    Runner-level failure (binary missing, no junit output, …).
     */
    public function __construct(
        public readonly array $classes = [],
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Aggregated results for `$testClasses`.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $testClasses  Class FQCNs.
     *
     * @return array{tests: int, assertions: int, failures: int, skipped: int}
     */
    public function totalsFor( array $testClasses ): array
    {
        $totals = [ 'tests' => 0, 'assertions' => 0, 'failures' => 0, 'skipped' => 0 ];

        foreach ( $testClasses as $class ) {
            foreach ( $totals as $metric => $value ) {
                $totals[ $metric ] = $value + (int) ( $this->classes[ $class ][ $metric ] ?? 0 );
            }
        }

        return $totals;
    }
}
