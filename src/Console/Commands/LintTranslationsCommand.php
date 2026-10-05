<?php

/**
 * LintTranslationsCommand.
 *
 * Build-time i18n lint (parent plan §16.5). Fails the build when:
 *
 * 1. a bare English string literal reaches a user-facing sink without going
 *    through `__()` / `trans_choice()`, or
 * 2. a key passed to `__()` / `trans_choice()` is missing from any shipped
 *    catalogue (`lang/{en,es,fr,de}.json`).
 *
 * Satellites lint themselves with `--no-engine --path=… --lang=…`: only
 * their own sources are scanned, against their own catalogues. Blade
 * templates are compiled before they are scanned.
 *
 * The rule the lint enforces: a string is user-facing when it can reach an
 * end user (shopper, store admin, API client) — HTTP problem responses,
 * validation messages, exception messages the HTTP / GraphQL layers render,
 * notification content, and display labels. Developer-facing strings — log
 * messages, console output, registry misuse, and internal invariant
 * exceptions that are never rendered — stay bare English.
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
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\ComponentTagCompiler;
use InvalidArgumentException;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class LintTranslationsCommand extends Command
{
    /**
     * Locales the engine ships catalogues for.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const LOCALES = [ 'en', 'es', 'fr', 'de' ];

    /**
     * Exception (and error) classes whose message the HTTP or GraphQL layer
     * renders to an end user. Every argument of a `new …()` for one of
     * these is a sink.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const USER_FACING_EXCEPTIONS = [
        'CartOperationException',
        'ClaimRateLimitedException',
        'ClaimVerificationFailedException',
        'DigitalDownloadException',
        'Error',
        'GraphQLError',
        'HttpException',
        'IncompatibleBoardSubstatusException',
        'InsufficientStockException',
        'InvalidOrderStatusTransitionException',
        'KanbanOperationException',
        'NotFoundHttpException',
        'NotificationTemplateException',
        'OrderNotEditableException',
        'PromotionUsageLimitReachedException',
        'RefundNotAllowedException',
        'SubstatusTransitionRejectedException',
        'UnprocessableEntityHttpException',
    ];

    /**
     * Exceptions whose message reaches a client only from these paths
     * (relative to a scan root): elsewhere they report programming errors.
     * `InvalidArgumentException` from these services and the reports
     * becomes an API `detail` (audit H4).
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, string>>
     */
    public const USER_FACING_EXCEPTIONS_IN = [
        'InvalidArgumentException' => [
            'Reports/',
            'Services/RefundService.php',
            'Services/OrderCancellationService.php',
            'Services/OrderNoteService.php',
            'Services/CustomerNoteService.php',
            'Services/ShipmentService.php',
        ],
    ];

    /**
     * Array keys whose value is display text.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const SINK_KEYS = [ 'label', 'title', 'message', 'description', 'subject', 'heading' ];

    /**
     * Path fragments whose files document the API for developers (OpenAPI,
     * GraphQL schema descriptions) rather than render to end users — the
     * listed keys are not sinks there.
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, string>>
     */
    public const DOCUMENTATION_PATHS = [
        'OpenApi/' => [ 'title', 'description' ],
        'GraphQL/' => [ 'description' ],
    ];

    /**
     * Path prefixes (relative to a scan root) never scanned for sinks: seed
     * and demo data, contract-test scaffolding, and console output. Keys
     * used there are still checked against the catalogues.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const EXCLUDED_SINK_PATHS = [
        'Database/Factories/',
        'Demo/',
        'Testing/',
        'Console/',
    ];

    /**
     * @var string
     */
    protected $signature = 'ecommerce:lint:translations
        {--path=* : Additional source directories to scan (relative to base_path() or absolute).}
        {--lang= : Catalogue directory to check (defaults to the engine\'s lang/).}
        {--no-engine : Scan only the --path directories, not the engine\'s own src/ (for linting a satellite).}
        {--sync : Add every missing key to en.json, with the English source as its own translation.}';

    /**
     * @var string
     */
    protected $description = 'Fail the build on bare user-facing strings or translation keys missing from a shipped catalogue.';

    /**
     * @since 1.0.0
     *
     * @return int
     */
    public function handle(): int
    {
        $violations = [];
        $keys       = [];
        $scanned    = 0;

        foreach ( $this->resolveSourcePaths() as $root ) {
            if ( ! is_dir( $root ) ) {
                continue;
            }

            $finder = ( new Finder() )->files()->in( $root )->name( '*.php' );

            foreach ( $finder as $file ) {
                $scanned++;
                $relative = str_replace( DIRECTORY_SEPARATOR, '/', $file->getRelativePathname() );
                $source   = $this->readSource( $file->getRealPath(), $relative, $violations );

                foreach ( $this->extractKeys( $source ) as $key ) {
                    $keys[ $key ] = true;
                }

                foreach ( $this->concatenatedKeyLines( $source ) as $line ) {
                    $violations[] = sprintf( 'Translation key must be a single string literal (no concatenation): %s:%d', $relative, $line );
                }

                if ( $this->isExcludedSinkPath( $relative ) ) {
                    continue;
                }

                foreach ( $this->scanSource( $source, $relative ) as $hit ) {
                    $reason = $this->extractIgnoreReason( $source, $hit['line'] );

                    if ( null !== $reason ) {
                        $this->line( sprintf( 'i18n-lint:ignore %s:%d "%s" reason: %s', $relative, $hit['line'], $hit['text'], $reason ) );

                        continue;
                    }

                    $violations[] = sprintf( 'Bare user-facing string: %s:%d [%s] "%s"', $relative, $hit['line'], $hit['sink'], $hit['text'] );
                }
            }
        }

        $langPath = $this->resolveLangPath();

        if ( (bool) $this->option( 'sync' ) ) {
            $this->syncEnglish( $langPath, array_keys( $keys ) );
        }

        foreach ( self::LOCALES as $locale ) {
            $catalogue = $this->readCatalogue( $langPath, $locale );

            foreach ( array_keys( $keys ) as $key ) {
                if ( ! array_key_exists( $key, $catalogue ) ) {
                    $violations[] = sprintf( 'Missing translation [%s]: "%s"', $locale, $key );
                }
            }
        }

        if ( [] === $violations ) {
            $this->info( sprintf(
                'Translation lint passed. Scanned %d file(s), %d key(s) across %d locale(s).',
                $scanned,
                count( $keys ),
                count( self::LOCALES ),
            ) );

            return self::SUCCESS;
        }

        foreach ( $violations as $violation ) {
            $this->error( $violation );
        }

        $this->error( sprintf( 'Translation lint failed with %d violation(s).', count( $violations ) ) );

        return self::FAILURE;
    }

    /**
     * Every translation key passed as a string literal to `__()` or
     * `trans_choice()` in `$source`.
     *
     * @since 1.0.0
     *
     * @param  string  $source  Raw PHP source.
     *
     * @return array<int, string>
     */
    public function extractKeys( string $source ): array
    {
        $tokens = $this->significantTokens( $source );
        $keys   = [];
        $count  = count( $tokens );

        for ( $i = 0; $i < $count - 2; $i++ ) {
            if ( $this->isTranslationCall( $tokens, $i ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $i + 2 ][0] && '.' !== ( $tokens[ $i + 3 ][1] ?? null ) ) {
                $keys[] = $this->decodeStringLiteral( $tokens[ $i + 2 ][1] );
            }
        }

        return array_values( array_unique( $keys ) );
    }

    /**
     * Lines where `__()` / `trans_choice()` receive a key built by string
     * concatenation. The catalogue can't hold such a key (and extracting
     * the first literal alone would report a bogus missing key), so it is
     * a violation in its own right.
     *
     * @since 1.0.0
     *
     * @param  string  $source  Raw PHP source.
     *
     * @return array<int, int>
     */
    public function concatenatedKeyLines( string $source ): array
    {
        $tokens = $this->significantTokens( $source );
        $count  = count( $tokens );
        $lines  = [];

        for ( $i = 0; $i < $count - 3; $i++ ) {
            if ( $this->isTranslationCall( $tokens, $i ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $i + 2 ][0] && '.' === $tokens[ $i + 3 ][1] ) {
                $lines[] = $tokens[ $i + 2 ][2];
            }
        }

        return $lines;
    }

    /**
     * Finds prose string literals that reach a user-facing sink without
     * passing through `__()` / `trans_choice()`.
     *
     * Sinks: every argument of `Problem::make()`, `abort()`, a validation
     * rule's `$fail()`, `ValidationException::withMessages()`, and
     * `new <UserFacingException>()` (some only in the paths of
     * {@see self::USER_FACING_EXCEPTIONS_IN}); `parent::__construct()` inside a
     * user-facing exception class; the body of a `messages()` method; and
     * the value of any {@see self::SINK_KEYS} array key.
     *
     * @since 1.0.0
     *
     * @param  string  $source    Raw PHP source.
     * @param  string  $relative  Path relative to the scan root, `/`-separated.
     *
     * @return array<int, array{line: int, sink: string, text: string}>
     */
    public function scanSource( string $source, string $relative = '' ): array
    {
        $tokens     = $this->significantTokens( $source );
        $count      = count( $tokens );
        $hits       = [];
        $userFacing = $this->declaresUserFacingException( $tokens );

        for ( $i = 0; $i < $count; $i++ ) {
            $sink  = null;
            $start = null;
            $end   = null;
            $open  = $this->callSinkOpen( $tokens, $i, $sink, $userFacing, $relative );

            if ( null !== $open ) {
                $start = $open + 1;
                $end   = $this->matchingClose( $tokens, $open );
            } elseif ( $this->isMessagesMethod( $tokens, $i ) ) {
                $brace = $this->nextIndexOf( $tokens, $i, '{' );

                if ( null !== $brace ) {
                    $sink  = 'messages()';
                    $start = $brace + 1;
                    $end   = $this->matchingClose( $tokens, $brace );
                }
            } elseif ( $this->isSinkKey( $tokens, $i, $relative ) ) {
                $sink  = "'" . $this->decodeStringLiteral( $tokens[ $i ][1] ) . "' =>";
                $start = $i + 2;
                $end   = $this->valueEnd( $tokens, $start );
            }

            if ( null === $sink || null === $start || null === $end ) {
                continue;
            }

            foreach ( $this->bareProseIn( $tokens, $start, $end ) as $hit ) {
                $hits[ $hit['line'] . ':' . $hit['text'] ] = [ 'line' => $hit['line'], 'sink' => $sink, 'text' => $hit['text'] ];
            }
        }

        return array_values( $hits );
    }

    /**
     * Whether a decoded literal reads as prose (display text) rather than
     * an identifier, slug, type name, or key — i.e. it has a word and
     * whitespace.
     *
     * @since 1.0.0
     *
     * @param  string  $value  Decoded string value.
     *
     * @return bool
     */
    public function isProse( string $value ): bool
    {
        $value = trim( $value );

        return '' !== $value
            && 1 === preg_match( '/\p{L}{2,}/u', $value )
            && 1 === preg_match( '/\s/u', $value );
    }

    /**
     * Compiles a Blade template to PHP without rendering it.
     *
     * Component tags are compiled first by a compiler that treats a
     * component it cannot resolve (one from a package the lint run did not
     * load) as an anonymous component, so its bound attributes are still
     * compiled — `:label="__( '…' )"` becomes `'label' => __( '…' )`. The
     * application's Blade compiler is cloned, so its custom directives apply
     * and its state is left alone.
     *
     * @since 1.0.0
     *
     * @param  string  $source  Template source.
     *
     * @return string
     */
    protected function compileBlade( string $source ): string
    {
        $blade = clone Blade::getFacadeRoot();
        $tags  = new class( $blade->getClassComponentAliases(), $blade->getClassComponentNamespaces(), $blade ) extends ComponentTagCompiler {
            /**
             * The component's class, or a placeholder view name when it
             * cannot be resolved.
             *
             * @since 1.0.0
             *
             * @param  string  $component  Component alias.
             *
             * @return string
             */
            public function componentClass( string $component )
            {
                try {
                    return parent::componentClass( $component );
                } catch ( InvalidArgumentException ) {
                    return 'i18n-lint-unresolved::' . $component;
                }
            }
        };

        $blade->withoutComponentTags();

        return $blade->compileString( $tags->compile( $source ) );
    }

    /**
     * The PHP source of a scanned file.
     *
     * Blade templates (`*.blade.php`) are compiled first, so keys in echoes,
     * directive arguments, and component attributes are checked like PHP;
     * plain template text stays out of scope. Line numbers reported for a
     * template refer to its compiled form. A template that fails to compile
     * is reported and scanned as written.
     *
     * @since 1.0.0
     *
     * @param  string              $path        Absolute path.
     * @param  string              $relative    Path relative to the scan root.
     * @param  array<int, string>  $violations  Collected violations.
     *
     * @return string
     */
    protected function readSource( string $path, string $relative, array &$violations ): string
    {
        $source = (string) file_get_contents( $path );

        if ( ! Str::endsWith( $relative, '.blade.php' ) ) {
            return $source;
        }

        try {
            return $this->compileBlade( $source );
        } catch ( Throwable $exception ) {
            $violations[] = sprintf( 'Blade template could not be compiled: %s (%s)', $relative, $exception->getMessage() );

            return $source;
        }
    }

    /**
     * Resolves the directories to scan: the engine's own `src/`, plus any
     * `--path` values.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    protected function resolveSourcePaths(): array
    {
        $paths = (bool) $this->option( 'no-engine' ) ? [] : [ realpath( __DIR__ . '/../..' ) ?: __DIR__ . '/../..' ];

        foreach ( (array) $this->option( 'path' ) as $additional ) {
            if ( '' === $additional ) {
                continue;
            }

            $paths[] = Str::startsWith( $additional, DIRECTORY_SEPARATOR ) ? $additional : base_path( $additional );
        }

        return array_values( array_unique( $paths ) );
    }

    /**
     * Catalogue directory: `--lang`, or the engine's own `lang/`.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function resolveLangPath(): string
    {
        $option = (string) $this->option( 'lang' );

        if ( '' !== $option ) {
            return Str::startsWith( $option, DIRECTORY_SEPARATOR ) ? $option : base_path( $option );
        }

        return realpath( __DIR__ . '/../../../lang' ) ?: __DIR__ . '/../../../lang';
    }

    /**
     * Reads `{$langPath}/{$locale}.json`, or an empty catalogue.
     *
     * @since 1.0.0
     *
     * @param  string  $langPath  Catalogue directory.
     * @param  string  $locale    Locale code.
     *
     * @return array<string, string>
     */
    protected function readCatalogue( string $langPath, string $locale ): array
    {
        $file = $langPath . DIRECTORY_SEPARATOR . $locale . '.json';

        if ( ! is_file( $file ) ) {
            return [];
        }

        $decoded = json_decode( (string) file_get_contents( $file ), true );

        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Adds every key missing from `en.json` as an identity translation and
     * rewrites the file with sorted keys. Other locales are left for a
     * translator; the lint keeps failing until they are filled in.
     *
     * @since 1.0.0
     *
     * @param  string              $langPath  Catalogue directory.
     * @param  array<int, string>  $keys      Keys used in source.
     *
     * @return void
     */
    protected function syncEnglish( string $langPath, array $keys ): void
    {
        $catalogue = $this->readCatalogue( $langPath, 'en' );
        $added     = 0;

        foreach ( $keys as $key ) {
            if ( ! array_key_exists( $key, $catalogue ) ) {
                $catalogue[ $key ] = $key;
                $added++;
            }
        }

        ksort( $catalogue, SORT_STRING );

        if ( ! is_dir( $langPath ) ) {
            mkdir( $langPath, 0755, true );
        }

        file_put_contents(
            $langPath . DIRECTORY_SEPARATOR . 'en.json',
            json_encode( $catalogue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n",
        );

        $this->info( sprintf( 'Synced %d new key(s) into en.json.', $added ) );
    }

    /**
     * Whether `$relative` sits under a path excluded from sink scanning.
     *
     * @since 1.0.0
     *
     * @param  string  $relative  Path relative to the scan root.
     *
     * @return bool
     */
    protected function isExcludedSinkPath( string $relative ): bool
    {
        foreach ( self::EXCLUDED_SINK_PATHS as $prefix ) {
            if ( str_starts_with( $relative, $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tokenizes `$source`, dropping whitespace and comments. Every token is
     * normalized to `[ id, text, line ]`.
     *
     * @since 1.0.0
     *
     * @param  string  $source  Raw PHP source.
     *
     * @return array<int, array{0: int|string, 1: string, 2: int}>
     */
    protected function significantTokens( string $source ): array
    {
        $out  = [];
        $line = 1;

        foreach ( token_get_all( $source ) as $token ) {
            if ( ! is_array( $token ) ) {
                $out[] = [ $token, $token, $line ];

                continue;
            }

            $line = (int) $token[2];

            if ( ! in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                $out[] = [ $token[0], $token[1], $line ];
            }

            $line += substr_count( $token[1], "\n" );
        }

        return $out;
    }

    /**
     * Whether the tokens at `$i` start a `__(` / `trans_choice(` call.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{0: int|string, 1: string, 2: int}>  $tokens  Significant tokens.
     * @param  int                                                  $i       Index.
     *
     * @return bool
     */
    protected function isTranslationCall( array $tokens, int $i ): bool
    {
        return in_array( $tokens[ $i ][0], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true )
            && in_array( ltrim( $tokens[ $i ][1], '\\' ), [ '__', 'trans_choice' ], true )
            && '(' === ( $tokens[ $i + 1 ][1] ?? null )
            && ! $this->isMemberAccess( $tokens, $i );
    }

    /**
     * When a call sink starts at `$i`, returns the index of its `(` and
     * sets `$sink` to a readable name.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{0: int|string, 1: string, 2: int}>  $tokens  Significant tokens.
     * @param  int                                                  $i       Index.
     * @param  string|null                                          $sink        Receives the sink name.
     * @param  bool                                                 $userFacing  Whether the file declares a user-facing exception.
     * @param  string                                               $relative    Path relative to the scan root.
     *
     * @return int|null
     */
    protected function callSinkOpen( array $tokens, int $i, ?string &$sink, bool $userFacing = false, string $relative = '' ): ?int
    {
        $text = $tokens[ $i ][1];

        if ( $userFacing && 'parent' === $text
            && '::' === ( $tokens[ $i + 1 ][1] ?? null )
            && '__construct' === ( $tokens[ $i + 2 ][1] ?? null )
            && '(' === ( $tokens[ $i + 3 ][1] ?? null ) ) {
            $sink = 'parent::__construct()';

            return $i + 3;
        }

        if ( T_VARIABLE === $tokens[ $i ][0] && '$fail' === $text && '(' === ( $tokens[ $i + 1 ][1] ?? null ) ) {
            $sink = '$fail()';

            return $i + 1;
        }

        if ( in_array( $this->shortName( $text ), [ 'Problem', 'ValidationException' ], true )
            && '::' === ( $tokens[ $i + 1 ][1] ?? null )
            && in_array( $tokens[ $i + 2 ][1] ?? null, [ 'make', 'withMessages' ], true )
            && '(' === ( $tokens[ $i + 3 ][1] ?? null ) ) {
            $sink = $this->shortName( $text ) . '::' . $tokens[ $i + 2 ][1] . '()';

            return $i + 3;
        }

        if ( in_array( $tokens[ $i ][0], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true ) && 'abort' === ltrim( $text, '\\' )
            && '(' === ( $tokens[ $i + 1 ][1] ?? null ) && ! $this->isMemberAccess( $tokens, $i ) ) {
            $sink = 'abort()';

            return $i + 1;
        }

        if ( T_NEW === $tokens[ $i ][0]
            && in_array( $tokens[ $i + 1 ][0] ?? null, [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ], true )
            && $this->isUserFacingException( $this->shortName( $tokens[ $i + 1 ][1] ), $relative )
            && '(' === ( $tokens[ $i + 2 ][1] ?? null ) ) {
            $sink = 'new ' . $this->shortName( $tokens[ $i + 1 ][1] ) . '()';

            return $i + 2;
        }

        return null;
    }

    /**
     * Whether a `new $exception()` in the file at `$relative` reaches a
     * client: always for {@see self::USER_FACING_EXCEPTIONS}, and inside
     * the listed paths for {@see self::USER_FACING_EXCEPTIONS_IN}.
     *
     * @since 1.0.0
     *
     * @param  string  $exception  Short class name.
     * @param  string  $relative   Path relative to the scan root.
     *
     * @return bool
     */
    protected function isUserFacingException( string $exception, string $relative ): bool
    {
        if ( in_array( $exception, self::USER_FACING_EXCEPTIONS, true ) ) {
            return true;
        }

        foreach ( self::USER_FACING_EXCEPTIONS_IN[ $exception ] ?? [] as $path ) {
            if ( str_contains( '/' . $relative, '/' . $path ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the file declares a class listed in
     * {@see self::USER_FACING_EXCEPTIONS}.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{0: int|string, 1: string, 2: int}>  $tokens  Significant tokens.
     *
     * @return bool
     */
    protected function declaresUserFacingException( array $tokens ): bool
    {
        foreach ( $tokens as $index => $token ) {
            if ( T_CLASS === $token[0] && in_array( $tokens[ $index + 1 ][1] ?? null, self::USER_FACING_EXCEPTIONS, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$i` is the `function` keyword of a `messages()` method.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{0: int|string, 1: string, 2: int}>  $tokens  Significant tokens.
     * @param  int                                                  $i       Index.
     *
     * @return bool
     */
    protected function isMessagesMethod( array $tokens, int $i ): bool
    {
        return T_FUNCTION === $tokens[ $i ][0] && 'messages' === ( $tokens[ $i + 1 ][1] ?? null );
    }

    /**
     * Whether `$i` is a {@see self::SINK_KEYS} literal followed by `=>`,
     * outside the documentation-path exemptions.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{0: int|string, 1: string, 2: int}>  $tokens    Significant tokens.
     * @param  int                                                  $i         Index.
     * @param  string                                               $relative  Relative file path.
     *
     * @return bool
     */
    protected function isSinkKey( array $tokens, int $i, string $relative ): bool
    {
        if ( T_CONSTANT_ENCAPSED_STRING !== $tokens[ $i ][0] || T_DOUBLE_ARROW !== ( $tokens[ $i + 1 ][0] ?? null ) ) {
            return false;
        }

        $key = $this->decodeStringLiteral( $tokens[ $i ][1] );

        if ( ! in_array( $key, self::SINK_KEYS, true ) ) {
            return false;
        }

        foreach ( self::DOCUMENTATION_PATHS as $fragment => $keys ) {
            if ( str_contains( '/' . $relative, '/' . $fragment ) && in_array( $key, $keys, true ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bare prose literals in `[$start, $end)` that are neither array keys
     * nor inside a translation call.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{0: int|string, 1: string, 2: int}>  $tokens  Significant tokens.
     * @param  int                                                  $start   First index.
     * @param  int                                                  $end     Stop index (exclusive).
     *
     * @return array<int, array{line: int, text: string}>
     */
    protected function bareProseIn( array $tokens, int $start, int $end ): array
    {
        $hits = [];

        for ( $j = $start; $j < $end; $j++ ) {
            if ( $this->isTranslationCall( $tokens, $j ) ) {
                $j = $this->matchingClose( $tokens, $j + 1 ) ?? $end;

                continue;
            }

            // Interpolated "…{$x}…" strings and heredocs/nowdocs: judge the
            // literal parts, with each interpolation shown as `{…}`.
            if ( '"' === $tokens[ $j ][0] || T_START_HEREDOC === $tokens[ $j ][0] ) {
                $close = '"' === $tokens[ $j ][0] ? '"' : T_END_HEREDOC;
                $line  = $tokens[ $j ][2];
                $text  = '';

                for ( $j++; $j < $end && $close !== $tokens[ $j ][0]; $j++ ) {
                    $text .= T_ENCAPSED_AND_WHITESPACE === $tokens[ $j ][0] ? $tokens[ $j ][1] : '{…}';
                }

                $text = (string) preg_replace( '/(\{…\})+/u', '{…}', $text );

                if ( $this->isProse( $text ) ) {
                    $hits[] = [ 'line' => $line, 'text' => Str::limit( trim( $text ), 80 ) ];
                }

                continue;
            }

            if ( T_CONSTANT_ENCAPSED_STRING !== $tokens[ $j ][0] || T_DOUBLE_ARROW === ( $tokens[ $j + 1 ][0] ?? null ) ) {
                continue;
            }

            $value = $this->decodeStringLiteral( $tokens[ $j ][1] );

            if ( $this->isProse( $value ) ) {
                $hits[] = [ 'line' => $tokens[ $j ][2], 'text' => Str::limit( $value, 80 ) ];
            }
        }

        return $hits;
    }

    /**
     * End (exclusive) of the array value starting at `$start`: the first
     * top-level `,`, `;`, or unmatched closing bracket.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{0: int|string, 1: string, 2: int}>  $tokens  Significant tokens.
     * @param  int                                                  $start   First value index.
     *
     * @return int
     */
    protected function valueEnd( array $tokens, int $start ): int
    {
        $depth = 0;
        $count = count( $tokens );

        for ( $j = $start; $j < $count; $j++ ) {
            if ( $this->isOpener( $tokens[ $j ] ) ) {
                $depth++;
            } elseif ( in_array( $tokens[ $j ][1], [ ')', ']', '}' ], true ) ) {
                if ( 0 === $depth ) {
                    return $j;
                }

                $depth--;
            } elseif ( 0 === $depth && in_array( $tokens[ $j ][1], [ ',', ';' ], true ) ) {
                return $j;
            }
        }

        return $count;
    }

    /**
     * Index of the bracket that closes the opener at `$open`.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{0: int|string, 1: string, 2: int}>  $tokens  Significant tokens.
     * @param  int                                                  $open    Opener index.
     *
     * @return int|null
     */
    protected function matchingClose( array $tokens, int $open ): ?int
    {
        $depth = 0;
        $count = count( $tokens );

        for ( $j = $open; $j < $count; $j++ ) {
            if ( $this->isOpener( $tokens[ $j ] ) ) {
                $depth++;
            } elseif ( in_array( $tokens[ $j ][1], [ ')', ']', '}' ], true ) ) {
                $depth--;

                if ( 0 === $depth ) {
                    return $j;
                }
            }
        }

        return null;
    }

    /**
     * Whether `$token` opens a bracket pair.
     *
     * @since 1.0.0
     *
     * @param  array{0: int|string, 1: string, 2: int}  $token  Significant token.
     *
     * @return bool
     */
    protected function isOpener( array $token ): bool
    {
        return in_array( $token[1], [ '(', '[', '{' ], true )
            || in_array( $token[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true );
    }

    /**
     * Index of the next token with text `$text` after `$i`, stopping at the
     * end of the statement.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{0: int|string, 1: string, 2: int}>  $tokens  Significant tokens.
     * @param  int                                                  $i       Start index.
     * @param  string                                               $text    Token text.
     *
     * @return int|null
     */
    protected function nextIndexOf( array $tokens, int $i, string $text ): ?int
    {
        $count = count( $tokens );

        for ( $j = $i + 1; $j < $count; $j++ ) {
            if ( $text === $tokens[ $j ][1] ) {
                return $j;
            }

            if ( ';' === $tokens[ $j ][1] ) {
                return null;
            }
        }

        return null;
    }

    /**
     * Whether the name at `$i` is a member access or a function declaration
     * rather than a global function call.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{0: int|string, 1: string, 2: int}>  $tokens  Significant tokens.
     * @param  int                                                  $i       Index.
     *
     * @return bool
     */
    protected function isMemberAccess( array $tokens, int $i ): bool
    {
        $previous = $tokens[ $i - 1 ] ?? null;

        return null !== $previous
            && ( in_array( $previous[1], [ '->', '?->', '::' ], true ) || T_FUNCTION === $previous[0] );
    }

    /**
     * Unqualified class name.
     *
     * @since 1.0.0
     *
     * @param  string  $name  Possibly-qualified name.
     *
     * @return string
     */
    protected function shortName( string $name ): string
    {
        $parts = explode( '\\', $name );

        return (string) end( $parts );
    }

    /**
     * Decodes a PHP string literal token to its runtime value.
     *
     * @since 1.0.0
     *
     * @param  string  $literal  Raw token text including quotes.
     *
     * @return string
     */
    protected function decodeStringLiteral( string $literal ): string
    {
        if ( strlen( $literal ) < 2 ) {
            return $literal;
        }

        $inner = substr( $literal, 1, -1 );

        if ( "'" === $literal[0] ) {
            return strtr( $inner, [ "\\'" => "'", '\\\\' => '\\' ] );
        }

        return stripcslashes( $inner );
    }

    /**
     * Reads a `// i18n-lint:ignore reason:<text>` annotation on `$line` or
     * on the line directly above it. A reason is required.
     *
     * @since 1.0.0
     *
     * @param  string  $source  Raw PHP source.
     * @param  int     $line    1-indexed line of the violation.
     *
     * @return string|null
     */
    protected function extractIgnoreReason( string $source, int $line ): ?string
    {
        foreach ( token_get_all( $source ) as $token ) {
            if ( ! is_array( $token ) || T_COMMENT !== $token[0] ) {
                continue;
            }

            $tokenLine = (int) $token[2];

            if ( $tokenLine !== $line && $tokenLine !== $line - 1 ) {
                continue;
            }

            $text = trim( $token[1] );

            if ( ! str_starts_with( $text, '//' ) && ! str_starts_with( $text, '#' ) ) {
                continue;
            }

            if ( 1 === preg_match( '/i18n-lint:ignore\s+reason:(.+)$/i', $text, $matches ) && '' !== trim( $matches[1] ) ) {
                return trim( $matches[1] );
            }
        }

        return null;
    }
}
