<?php

/**
 * TestClassLocator.
 *
 * Statically scans a satellite's test directories for concrete classes
 * that extend one of the engine's abstract contract suites, directly or
 * through the satellite's own intermediate base classes. Files are parsed
 * with the PHP tokenizer, never included, so a broken test file can't
 * take the verifier down with it.
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

use Symfony\Component\Finder\Finder;

/**
 * Locates the satellite's contract-test subclasses.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class TestClassLocator
{
    /**
     * Concrete test classes keyed by the abstract suite they (transitively)
     * extend. Each entry carries the class FQCN and its file source, which
     * {@see self::classesFor()} uses to match tests to implementations.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $paths  Absolute directories to scan.
     *
     * @return array<class-string, array<int, array{class: string, source: string}>>
     */
    public function locate( array $paths ): array
    {
        $classes = [];

        foreach ( $paths as $path ) {
            if ( ! is_dir( $path ) ) {
                continue;
            }

            foreach ( ( new Finder() )->files()->in( $path )->name( '*.php' ) as $file ) {
                $source = (string) file_get_contents( $file->getRealPath() );

                foreach ( $this->parse( $source ) as $class ) {
                    $classes[ $class['class'] ] = $class + [ 'source' => $source ];
                }
            }
        }

        $suites  = array_values( ContractSuiteMap::SUITES );
        $bySuite = [];

        foreach ( $classes as $class ) {
            if ( $class['abstract'] ) {
                continue;
            }

            $suite = $this->resolveSuite( $class['class'], $classes, $suites );

            if ( null !== $suite ) {
                $bySuite[ $suite ][] = [ 'class' => $class['class'], 'source' => $class['source'] ];
            }
        }

        return $bySuite;
    }

    /**
     * The test classes that cover `$registration`.
     *
     * A test covers an implementation when its source references the
     * implementation (FQCN or imported short name). When a satellite ships
     * exactly one implementation of a contract, a quoted registry key
     * (`->get( 'customer' )`) also counts, and failing any reference every
     * suite subclass for that contract covers it, so single-implementation
     * satellites don't have to name the class explicitly.
     *
     * @since 1.0.0
     *
     * @param  Registration                                                   $registration  Registration to match.
     * @param  array<class-string, array<int, array{class: string, source: string}>> $located  Output of {@see self::locate()}.
     * @param  int                                                            $siblings      How many registrations share this contract.
     *
     * @return array<int, string>
     */
    public function classesFor( Registration $registration, array $located, int $siblings ): array
    {
        if ( null === $registration->suite ) {
            return [];
        }

        $candidates = $located[ $registration->suite ] ?? [];
        $short      = ContractSuiteMap::shortName( $registration->class );
        $matched    = [];

        foreach ( $candidates as $candidate ) {
            $mentionsFqcn  = str_contains( $candidate['source'], $registration->class );
            $mentionsShort = 1 === preg_match( '/\b' . preg_quote( $short, '/' ) . '\b/', $candidate['source'] );

            // A quoted registry key is weak evidence — a sibling's test can
            // mention it in passing (e.g. a subscription test referencing
            // 'bundle'). It only counts when this is the contract's sole
            // registration; siblings must be matched by class.
            $mentionsKey = 1 === $siblings
                && 1 === preg_match( '/([\'"])' . preg_quote( $registration->key, '/' ) . '\1/', $candidate['source'] );

            if ( $mentionsFqcn || $mentionsShort || $mentionsKey ) {
                $matched[] = $candidate['class'];
            }
        }

        if ( [] === $matched && 1 === $siblings ) {
            $matched = array_column( $candidates, 'class' );
        }

        sort( $matched );

        return array_values( array_unique( $matched ) );
    }

    /**
     * Parses class declarations out of a PHP source file.
     *
     * @since 1.0.0
     *
     * @param  string  $source  PHP source.
     *
     * @return array<int, array{class: string, extends: string|null, abstract: bool}>
     */
    public function parse( string $source ): array
    {
        $tokens    = token_get_all( $source );
        $count     = count( $tokens );
        $namespace = '';
        $imports   = [];
        $classes   = [];
        $depth     = 0;

        for ( $i = 0; $i < $count; $i++ ) {
            $token = $tokens[ $i ];

            if ( ! is_array( $token ) ) {
                if ( '{' === $token ) {
                    $depth++;
                } elseif ( '}' === $token ) {
                    $depth--;
                }

                continue;
            }

            if ( in_array( $token[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) {
                $depth++;

                continue;
            }

            if ( T_NAMESPACE === $token[0] ) {
                $namespace = $this->readName( $tokens, $i + 1 );
                $imports   = [];

                continue;
            }

            if ( T_USE === $token[0] && 0 === $depth ) {
                $name  = $this->readName( $tokens, $i + 1 );
                $alias = ContractSuiteMap::shortName( $name );

                for ( $j = $i + 1; $j < $count && ';' !== $tokens[ $j ] && ',' !== $tokens[ $j ]; $j++ ) {
                    if ( is_array( $tokens[ $j ] ) && T_AS === $tokens[ $j ][0] ) {
                        $alias = $this->readName( $tokens, $j + 1 );
                    }
                }

                if ( '' !== $name ) {
                    $imports[ strtolower( $alias ) ] = $name;
                }

                continue;
            }

            if ( T_CLASS !== $token[0] ) {
                continue;
            }

            $previous = $this->previousSignificant( $tokens, $i );

            if ( null !== $previous && ( T_DOUBLE_COLON === $previous[0] || T_NEW === $previous[0] ) ) {
                continue;
            }

            $name = $this->readName( $tokens, $i + 1 );

            if ( '' === $name ) {
                continue;
            }

            $extends  = null;
            $abstract = false;

            for ( $j = $i - 1; $j >= 0; $j-- ) {
                if ( ! is_array( $tokens[ $j ] ) ) {
                    break;
                }

                if ( T_ABSTRACT === $tokens[ $j ][0] ) {
                    $abstract = true;
                } elseif ( ! in_array( $tokens[ $j ][0], [ T_WHITESPACE, T_FINAL, T_READONLY, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                    break;
                }
            }

            for ( $j = $i + 1; $j < $count && '{' !== $tokens[ $j ]; $j++ ) {
                if ( is_array( $tokens[ $j ] ) && T_EXTENDS === $tokens[ $j ][0] ) {
                    $extends = $this->resolve( $this->readName( $tokens, $j + 1 ), $namespace, $imports );

                    break;
                }
            }

            $classes[] = [
                'class'    => ltrim( ( '' === $namespace ? '' : $namespace . '\\' ) . $name, '\\' ),
                'extends'  => $extends,
                'abstract' => $abstract,
            ];
        }

        return $classes;
    }

    /**
     * Follows `$class`'s `extends` chain through the scanned classes until it
     * reaches an engine suite (or runs out).
     *
     * @since 1.0.0
     *
     * @param  string                                                                  $class    Class to resolve.
     * @param  array<string, array{class: string, extends: string|null, abstract: bool}> $classes  Scanned classes.
     * @param  array<int, string>                                                      $suites   Engine suite FQCNs.
     *
     * @return string|null
     */
    protected function resolveSuite( string $class, array $classes, array $suites ): ?string
    {
        $seen = [];

        for ( $current = $classes[ $class ]['extends'] ?? null; null !== $current; $current = $classes[ $current ]['extends'] ?? null ) {
            if ( in_array( $current, $suites, true ) ) {
                return $current;
            }

            if ( isset( $seen[ $current ] ) || ! isset( $classes[ $current ] ) ) {
                return null;
            }

            $seen[ $current ] = true;
        }

        return null;
    }

    /**
     * Resolves a class reference against the file's namespace and imports.
     *
     * @since 1.0.0
     *
     * @param  string                 $name       Name as written.
     * @param  string                 $namespace  Current namespace.
     * @param  array<string, string>  $imports    Lower-cased alias → FQCN.
     *
     * @return string
     */
    protected function resolve( string $name, string $namespace, array $imports ): string
    {
        if ( str_starts_with( $name, '\\' ) ) {
            return ltrim( $name, '\\' );
        }

        $parts = explode( '\\', $name );
        $first = strtolower( $parts[0] );

        if ( isset( $imports[ $first ] ) ) {
            $parts[0] = $imports[ $first ];

            return implode( '\\', $parts );
        }

        return ltrim( ( '' === $namespace ? '' : $namespace . '\\' ) . $name, '\\' );
    }

    /**
     * Reads a (possibly qualified) name starting at `$index`, skipping
     * leading whitespace.
     *
     * @since 1.0.0
     *
     * @param  array<int, mixed>  $tokens  Token stream.
     * @param  int                $index   Start index.
     *
     * @return string
     */
    protected function readName( array $tokens, int $index ): string
    {
        $name  = '';
        $count = count( $tokens );

        for ( $i = $index; $i < $count; $i++ ) {
            $token = $tokens[ $i ];

            if ( ! is_array( $token ) ) {
                break;
            }

            if ( T_WHITESPACE === $token[0] ) {
                if ( '' === $name ) {
                    continue;
                }

                break;
            }

            if ( in_array( $token[0], [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR ], true ) ) {
                $name .= $token[1];

                continue;
            }

            break;
        }

        return $name;
    }

    /**
     * The nearest non-whitespace, non-comment token before `$index`.
     *
     * @since 1.0.0
     *
     * @param  array<int, mixed>  $tokens  Token stream.
     * @param  int                $index   Start index.
     *
     * @return array<int, mixed>|null
     */
    protected function previousSignificant( array $tokens, int $index ): ?array
    {
        for ( $i = $index - 1; $i >= 0; $i-- ) {
            $token = $tokens[ $i ];

            if ( ! is_array( $token ) ) {
                return null;
            }

            if ( ! in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                return $token;
            }
        }

        return null;
    }
}
