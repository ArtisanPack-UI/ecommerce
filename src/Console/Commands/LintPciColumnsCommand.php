<?php

/**
 * LintPciColumnsCommand.
 *
 * Build-time lint that scans every migration file for column-name substrings
 * that would place the engine outside its SAQ-A PCI commitment. Fails the
 * build on any hit unless the offending line carries an inline
 * `// pci-lint:ignore reason:<text>` annotation.
 *
 * See engine spec §11.1.
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

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class LintPciColumnsCommand extends Command
{

    /**
     * Column-name substrings that are forbidden under the engine's SAQ-A
     * commitment. See engine spec §11.1.
     *
     * @var array<int, string>
     */
    protected const FORBIDDEN_SUBSTRINGS = [
        'card_number',
        'cardnumber',
        'pan',
        'cvv',
        'cvc',
        'card_cvc',
        'card_cvv',
        'card_expiry',
        'cardholder',
        'raw_card',
        'card_track',
        'magstripe',
    ];

    /**
     * @var string
     */
    protected $signature = 'ecommerce:lint:pci-columns
        {--path=* : Additional migration directories to scan (relative to base_path() or absolute).}';

    /**
     * @var string
     */
    protected $description = 'Fail the build if any migration declares a PCI-sensitive column name.';

    /**
     * @since 1.0.0
     *
     * @return int
     */
    public function handle(): int
    {
        $paths      = $this->resolveMigrationPaths();
        $violations = [];
        $ignored    = [];
        $scanned    = 0;

        foreach ( $paths as $path ) {
            if ( ! is_dir( $path ) ) {
                continue;
            }

            $finder = ( new Finder() )
                ->files()
                ->in( $path )
                ->name( '*.php' );

            foreach ( $finder as $file ) {
                $scanned++;
                $lines = preg_split( '/\R/', (string) file_get_contents( $file->getRealPath() ) ) ?: [];

                foreach ( $lines as $index => $line ) {
                    $match = $this->findForbiddenMatch( $line );
                    if ( null === $match ) {
                        continue;
                    }

                    $lineNumber = $index + 1;
                    $ignore     = $this->extractIgnoreReason( $line );

                    if ( null !== $ignore ) {
                        $ignored[] = [
                            'file'   => $file->getRelativePathname(),
                            'path'   => $file->getRealPath(),
                            'line'   => $lineNumber,
                            'match'  => $match,
                            'reason' => $ignore,
                        ];
                        continue;
                    }

                    $violations[] = [
                        'file'    => $file->getRelativePathname(),
                        'path'    => $file->getRealPath(),
                        'line'    => $lineNumber,
                        'match'   => $match,
                        'snippet' => trim( $line ),
                    ];
                }
            }
        }

        foreach ( $ignored as $entry ) {
            $this->line( sprintf(
                'pci-lint:ignore %s:%d [%s] reason: %s',
                $entry['file'],
                $entry['line'],
                $entry['match'],
                $entry['reason'],
            ) );
        }

        if ( [] === $violations ) {
            $this->info( sprintf( 'PCI column lint passed. Scanned %d migration file(s).', $scanned ) );

            return self::SUCCESS;
        }

        foreach ( $violations as $violation ) {
            $this->error( sprintf(
                'PCI column violation: %s:%d matched "%s" — %s',
                $violation['file'],
                $violation['line'],
                $violation['match'],
                $violation['snippet'],
            ) );
        }

        $this->error( sprintf(
            'PCI column lint failed with %d violation(s) across %d migration file(s).',
            count( $violations ),
            $scanned,
        ) );

        return self::FAILURE;
    }

    /**
     * Resolve the migration directories to scan.
     *
     * Always includes the engine's own `database/migrations` directory. If a
     * consumer app is running the command, `database_path( 'migrations' )` is
     * also scanned. Additional paths may be passed via `--path=`.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    protected function resolveMigrationPaths(): array
    {
        $paths = [
            realpath( __DIR__ . '/../../../database/migrations' ) ?: __DIR__ . '/../../../database/migrations',
        ];

        if ( function_exists( 'database_path' ) ) {
            $paths[] = database_path( 'migrations' );
        }

        foreach ( (array) $this->option( 'path' ) as $additional ) {
            if ( '' === $additional ) {
                continue;
            }

            $paths[] = Str::startsWith( $additional, DIRECTORY_SEPARATOR )
                ? $additional
                : base_path( $additional );
        }

        return array_values( array_unique( $paths ) );
    }

    /**
     * Return the first forbidden substring that appears as part of a column
     * name on the given line, or null if none appear. Matching is
     * case-insensitive.
     *
     * A "column name" is any string literal (single- or double-quoted) on
     * the line. This keeps the lint focused on schema declarations and
     * avoids false positives on common English words that happen to contain
     * a forbidden substring (e.g. `company` containing `pan`).
     *
     * @since 1.0.0
     *
     * @param  string  $line  Raw file line.
     *
     * @return string|null
     */
    protected function findForbiddenMatch( string $line ): ?string
    {
        if ( 0 === preg_match_all( '/([\'"])([^\'"\\\\]*)\\1/', $line, $matches ) ) {
            return null;
        }

        foreach ( $matches[ 2 ] as $literal ) {
            $tokens = preg_split( '/[^A-Za-z0-9_]+/', strtolower( $literal ) ) ?: [];

            foreach ( $tokens as $token ) {
                if ( '' === $token ) {
                    continue;
                }

                foreach ( self::FORBIDDEN_SUBSTRINGS as $needle ) {
                    $pattern = '/(?:^|_)' . preg_quote( $needle, '/' ) . '(?:_|$)/';

                    if ( 1 === preg_match( $pattern, $token ) ) {
                        return $needle;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract the reason from an inline `// pci-lint:ignore reason:<text>`
     * annotation, if present on the given line. Returns null when no
     * annotation is present or when the reason string is empty.
     *
     * @since 1.0.0
     *
     * @param  string  $line  Raw file line.
     *
     * @return string|null
     */
    protected function extractIgnoreReason( string $line ): ?string
    {
        $stripped = preg_replace( '/([\'"])(?:[^\'"\\\\]|\\\\.)*\\1/', '', $line ) ?? $line;

        if ( 1 !== preg_match( '~//[^\r\n]*?pci-lint:ignore\s+reason:(.+)$~i', $stripped, $matches ) ) {
            return null;
        }

        $reason = trim( $matches[ 1 ] );

        return '' === $reason ? null : $reason;
    }
}
