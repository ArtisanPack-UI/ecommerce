<?php

/**
 * VerificationReport.
 *
 * The JSON report `ecommerce:verify-satellite` writes to
 * `.ecommerce-verify-report.json`. The serialized bytes are what CI signs
 * and what `ecommerce_satellites.verified_report_hash` records, so
 * serialization is deterministic for a given set of results.
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
 * Satellite verification report.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class VerificationReport
{
    /**
     * Report format version. Bump on any breaking change to the JSON shape.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const SCHEMA_VERSION = 1;

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_MISSING = 'missing';

    public const STATUS_NO_SUITE = 'no-suite';

    public const STATUS_SKIPPED = 'not-run';

    /**
     * Memoized JSON serialization.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    private ?string $json = null;

    /**
     * @since 1.0.0
     *
     * @param  string                            $package        Satellite composer name.
     * @param  string                            $version        Satellite version verified.
     * @param  string                            $engineVersion  Engine version the suites came from.
     * @param  string                            $generatedAt    ISO-8601 UTC timestamp.
     * @param  string                            $phpVersion     PHP version the suites ran on.
     * @param  array<int, array<string, mixed>>  $registrations  Per-registration rows.
     * @param  bool                              $ran            Whether the suites were executed.
     * @param  bool                              $allowEmpty     Whether a satellite with no registrations counts as verified.
     * @param  string|null                       $runnerError    Runner-level failure, if any.
     * @param  array<int, string>                $namespaces     Namespace prefixes attributed to the satellite.
     */
    public function __construct(
        public readonly string $package,
        public readonly string $version,
        public readonly string $engineVersion,
        public readonly string $generatedAt,
        public readonly string $phpVersion,
        public readonly array $registrations,
        public readonly bool $ran = true,
        public readonly bool $allowEmpty = false,
        public readonly ?string $runnerError = null,
        public readonly array $namespaces = [],
    ) {
    }

    /**
     * Counts per status plus the registration total.
     *
     * @since 1.0.0
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $summary = [
            'registrations'        => count( $this->registrations ),
            self::STATUS_PASSED    => 0,
            self::STATUS_FAILED    => 0,
            self::STATUS_MISSING   => 0,
            self::STATUS_NO_SUITE  => 0,
            self::STATUS_SKIPPED   => 0,
        ];

        foreach ( $this->registrations as $row ) {
            $summary[ $row['status'] ] = ( $summary[ $row['status'] ] ?? 0 ) + 1;
        }

        return $summary;
    }

    /**
     * Whether the satellite passes: suites ran, nothing failed or is
     * missing, and (unless `$allowEmpty`) it registered at least one
     * implementation.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function verified(): bool
    {
        $summary = $this->summary();

        if ( ! $this->ran || null !== $this->runnerError ) {
            return false;
        }

        if ( 0 === $summary['registrations'] ) {
            return $this->allowEmpty;
        }

        return 0 === $summary[ self::STATUS_FAILED ] && 0 === $summary[ self::STATUS_MISSING ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema'         => self::SCHEMA_VERSION,
            'package'        => $this->package,
            'version'        => $this->version,
            'engine_version' => $this->engineVersion,
            'generated_at'   => $this->generatedAt,
            'php_version'    => $this->phpVersion,
            'namespaces'     => $this->namespaces,
            'ran'            => $this->ran,
            'allow_empty'    => $this->allowEmpty,
            'runner_error'   => $this->runnerError,
            'registrations'  => $this->registrations,
            'summary'        => $this->summary(),
            'verified'       => $this->verified(),
        ];
    }

    /**
     * Pretty-printed JSON with a trailing newline — the exact bytes written
     * to disk, hashed, and signed.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function toJson(): string
    {
        return $this->json ??= json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * SHA-256 hex digest of {@see self::toJson()}. This is the value stored
     * in `ecommerce_satellites.verified_report_hash`.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function hash(): string
    {
        return hash( 'sha256', $this->toJson() );
    }
}
