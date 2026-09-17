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
                $source = (string) file_get_contents( $file->getRealPath() );
                $lines  = preg_split( '/\R/', $source ) ?: [];
                $hits   = $this->scanSource( $source );

                foreach ( $hits as $hit ) {
                    $reason = $this->extractIgnoreReasonForLine( $source, $hit['line'] );

                    if ( null !== $reason ) {
                        $ignored[] = [
                            'file'   => $file->getRelativePathname(),
                            'path'   => $file->getRealPath(),
                            'line'   => $hit['line'],
                            'match'  => $hit['match'],
                            'reason' => $reason,
                        ];
                        continue;
                    }

                    $snippet = $lines[ $hit['line'] - 1 ] ?? '';

                    $violations[] = [
                        'file'    => $file->getRelativePathname(),
                        'path'    => $file->getRealPath(),
                        'line'    => $hit['line'],
                        'match'   => $hit['match'],
                        'snippet' => trim( $snippet ),
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
     * Tokenize the given PHP source and return every string-literal hit
     * against the forbidden substring list. Uses `token_get_all()` so escape
     * sequences inside double-quoted strings (`"card_\x6eumber"`) are
     * decoded before matching, closing the obvious bypass path.
     *
     * @since 1.0.0
     *
     * @param  string  $source  Raw file contents.
     *
     * @return array<int, array{line: int, match: string}>
     */
    protected function scanSource( string $source ): array
    {
        $hits = [];

        foreach ( token_get_all( $source ) as $token ) {
            if ( ! is_array( $token ) ) {
                continue;
            }

            if ( T_CONSTANT_ENCAPSED_STRING !== $token[ 0 ] ) {
                continue;
            }

            $value = $this->decodeStringLiteral( $token[ 1 ] );
            $match = $this->findForbiddenInValue( $value );

            if ( null === $match ) {
                continue;
            }

            $hits[] = [
                'line'  => (int) $token[ 2 ],
                'match' => $match,
            ];
        }

        return $hits;
    }

    /**
     * Decode a PHP string literal token to its runtime value. Handles both
     * single- and double-quoted literals with their respective escape rules.
     *
     * @since 1.0.0
     *
     * @param  string  $literal  Raw token text including surrounding quotes.
     *
     * @return string
     */
    protected function decodeStringLiteral( string $literal ): string
    {
        if ( '' === $literal ) {
            return '';
        }

        $quote = $literal[ 0 ];
        $inner = substr( $literal, 1, -1 );

        if ( "'" === $quote ) {
            return strtr( $inner, [ "\\'" => "'", '\\\\' => '\\' ] );
        }

        return stripcslashes( $inner );
    }

    /**
     * Return the first forbidden substring that appears as a snake_case
     * token inside the given decoded string value.
     *
     * @since 1.0.0
     *
     * @param  string  $value  Decoded string literal value.
     *
     * @return string|null
     */
    protected function findForbiddenInValue( string $value ): ?string
    {
        $tokens = preg_split( '/[^A-Za-z0-9_]+/', strtolower( $value ) ) ?: [];

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

        return null;
    }

    /**
     * Look for a `// pci-lint:ignore reason:<text>` annotation among the
     * line-comment tokens that share the given line number. Block comments
     * (`/* ... *\/`) are intentionally rejected so an annotation must live
     * in a real end-of-line comment.
     *
     * @since 1.0.0
     *
     * @param  string  $source  Raw file contents.
     * @param  int     $line    1-indexed line number of the violation.
     *
     * @return string|null Trimmed reason string, or null when no valid
     *                     annotation is present on the same line.
     */
    protected function extractIgnoreReasonForLine( string $source, int $line ): ?string
    {
        foreach ( token_get_all( $source ) as $token ) {
            if ( ! is_array( $token ) || T_COMMENT !== $token[ 0 ] ) {
                continue;
            }

            if ( (int) $token[ 2 ] !== $line ) {
                continue;
            }

            $text = $token[ 1 ];

            if ( ! str_starts_with( ltrim( $text ), '//' ) && ! str_starts_with( ltrim( $text ), '#' ) ) {
                continue;
            }

            if ( 1 !== preg_match( '/pci-lint:ignore\s+reason:(.+)$/i', $text, $matches ) ) {
                continue;
            }

            $reason = trim( $matches[ 1 ] );

            if ( '' === $reason ) {
                return null;
            }

            return $reason;
        }

        return null;
    }
}
