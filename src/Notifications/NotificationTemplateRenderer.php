<?php

/**
 * NotificationTemplateRenderer.
 *
 * Renders store-owner-editable notification copy with Twig running inside
 * `twig/twig`'s sandbox (parent plan §14.2, §16.5). Every template is
 * sandboxed: only the tags, filters, functions, and tests whitelisted in
 * {@see self::policy()} compile, no method or property of any object may
 * be touched, and the render context is plain arrays built by
 * {@see NotificationContext} — so there is no path from a template to
 * arbitrary PHP. `{{ system('rm -rf /') }}` is a compile error.
 *
 * {@see self::validate()} runs when a template is saved: it compiles both
 * sources under the sandbox, rejects the `..` range operator (an easy
 * memory bomb) and `for` loops nested deeper than {@see self::MAX_LOOP_DEPTH},
 * and rejects references to variables the template doesn't declare. Runtime rendering ({@see self::renderTemplate()}) and the
 * editor's live preview use the same entry point, so a preview renders
 * exactly as the notification will.
 *
 * Mail bodies are HTML with autoescaping on; subjects and non-mail bodies
 * are plain text.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Notifications;

use ArtisanPackUI\Ecommerce\Exceptions\NotificationTemplateException;
use ArtisanPackUI\Ecommerce\Support\LocalizedDate;
use DateTimeInterface;
use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Node\Expression\AssignNameExpression;
use Twig\Node\Expression\Binary\RangeBinary;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\ForNode;
use Twig\Node\Node;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityPolicy;
use Twig\Source;
use Twig\TwigFilter;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NotificationTemplateRenderer
{
    /**
     * Tags a template may use. `set` is left out: repeated
     * `{% set s = s ~ s %}` doubles a string until PHP runs out of memory.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const ALLOWED_TAGS = [ 'if', 'for', 'apply' ];

    /**
     * Filters a template may use. `localized_date` is the engine's own: it
     * formats a date in the active locale via `Carbon::translatedFormat()`
     * (Twig's `date` filter always prints English month names). Deliberately
     * absent: callable-taking filters (`map`, `filter`, `reduce`, `sort`),
     * `raw`, and filters that can build arbitrarily large values from a
     * short source — `batch` (its fill argument pads to any size), `format`
     * (sprintf widths), and `split` (turns a literal into a loopable list).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const ALLOWED_FILTERS = [
        'abs',
        'capitalize',
        'date',
        'default',
        'e',
        'escape',
        'first',
        'join',
        'keys',
        'last',
        'length',
        'localized_date',
        'lower',
        'merge',
        'nl2br',
        'number_format',
        'replace',
        'round',
        'slice',
        'striptags',
        'title',
        'trim',
        'upper',
        'url_encode',
    ];

    /**
     * Functions a template may call.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const ALLOWED_FUNCTIONS = [ 'max', 'min' ];

    /**
     * Tests a template may use.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const ALLOWED_TESTS = [ 'defined', 'divisible by', 'empty', 'even', 'iterable', 'none', 'null', 'odd', 'same as' ];

    /**
     * Deepest `for` nesting a template may use. Loops over literal lists
     * are bounded by the source length; capping the nesting keeps their
     * product bounded too.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_LOOP_DEPTH = 2;

    /**
     * Renders a template's subject and body for `$channel`.
     *
     * The body source runs through `ap.ecommerce.notification.rendering`
     * first (engine spec §6.15).
     *
     * @since 1.0.0
     *
     * @param  string                $templateKey  Template key (for the filter).
     * @param  string|null           $subject      Subject source.
     * @param  string                $body         Body source.
     * @param  array<string, mixed>  $variables    Render context.
     * @param  string                $channel      Delivery channel.
     *
     * @throws NotificationTemplateException When a source fails to compile or render.
     *
     * @return array{subject: string|null, body: string}
     */
    public function renderTemplate( string $templateKey, ?string $subject, string $body, array $variables, string $channel ): array
    {
        $body = (string) applyFilters( 'ap.ecommerce.notification.rendering', $body, $templateKey, $variables );

        return [
            'subject' => null === $subject ? null : trim( $this->render( $subject, $variables, false, 'subject' ) ),
            'body'    => $this->render( $body, $variables, 'mail' === $channel, 'body' ),
        ];
    }

    /**
     * Renders one source inside the sandbox.
     *
     * @since 1.0.0
     *
     * @param  string                $source     Twig source.
     * @param  array<string, mixed>  $variables  Render context.
     * @param  bool                  $html       Autoescape as HTML.
     * @param  string                $field      Field name, for error reporting.
     *
     * @throws NotificationTemplateException When the source fails to compile or render.
     *
     * @return string
     */
    public function render( string $source, array $variables, bool $html, string $field = 'body' ): string
    {
        try {
            return $this->environment()->createTemplate( $source, ( $html ? 'html:' : 'text:' ) . $field )->render( $variables );
        } catch ( TwigError $error ) {
            throw new NotificationTemplateException( [ $this->errorFor( $field, $error ) ], $error );
        }
    }

    /**
     * Validates sources before they are saved: each must compile under the
     * sandbox, render against `$sampleData`, avoid the range operator, and
     * reference only `$declared` variables. Every problem is reported.
     *
     * @since 1.0.0
     *
     * @param  array<string, string|null>  $sources     Field → source (`subject`, `body`).
     * @param  array<int, string>          $declared    Declared variable paths.
     * @param  array<string, mixed>        $sampleData  Context to trial-render with.
     * @param  bool                        $htmlBody    Whether the body is HTML.
     *
     * @throws NotificationTemplateException When any source is invalid.
     *
     * @return void
     */
    public function validate( array $sources, array $declared, array $sampleData = [], bool $htmlBody = true ): void
    {
        $errors = [];

        foreach ( $sources as $field => $source ) {
            if ( null === $source ) {
                continue;
            }

            try {
                $module = $this->environment()->parse( $this->environment()->tokenize( new Source( $source, $field ) ) );
            } catch ( TwigError $error ) {
                $errors[] = $this->errorFor( $field, $error );

                continue;
            }

            $fieldErrors = $this->inspect( $module, $declared, $field );

            if ( [] === $fieldErrors ) {
                try {
                    $this->render( $source, $sampleData, 'body' === $field && $htmlBody, $field );
                } catch ( NotificationTemplateException $exception ) {
                    $fieldErrors = $exception->errors;
                }
            }

            array_push( $errors, ...$fieldErrors );
        }

        if ( [] !== $errors ) {
            throw new NotificationTemplateException( $errors );
        }
    }

    /**
     * The sandbox policy.
     *
     * @since 1.0.0
     *
     * @return SecurityPolicy
     */
    public function policy(): SecurityPolicy
    {
        return new SecurityPolicy( self::ALLOWED_TAGS, self::ALLOWED_FILTERS, [], [], self::ALLOWED_FUNCTIONS, self::ALLOWED_TESTS );
    }

    /**
     * A fresh, globally sandboxed Twig environment. Nothing is cached to
     * disk, and strict variables are off so optional data renders empty.
     *
     * @since 1.0.0
     *
     * @return Environment
     */
    protected function environment(): Environment
    {
        $environment = new Environment( new ArrayLoader(), [
            'cache'            => false,
            'strict_variables' => false,
            'autoescape'       => static fn ( string $name ): string|false => str_starts_with( $name, 'html:' ) ? 'html' : false,
        ] );

        $environment->addFilter( new TwigFilter(
            'localized_date',
            static fn ( mixed $date, ?string $format = null ): string => LocalizedDate::format(
                $date instanceof DateTimeInterface || is_string( $date ) ? $date : null,
                $format,
            ),
        ) );

        $environment->addExtension( new SandboxExtension( $this->policy(), true ) );

        return $environment;
    }

    /**
     * Checks a parsed template for the range operator and undeclared
     * variables.
     *
     * @since 1.0.0
     *
     * @param  Node                $module    Parsed template.
     * @param  array<int, string>  $declared  Declared variable paths.
     * @param  string              $field     Field name.
     *
     * @return array<int, array{field: string, code: string, message: string}>
     */
    protected function inspect( Node $module, array $declared, string $field ): array
    {
        $references = [];
        $locals     = [];
        $usesRange  = false;

        $this->collect( $module, $references, $locals, $usesRange );

        $errors = [];

        if ( $usesRange ) {
            $errors[] = [ 'field' => $field, 'code' => 'forbidden-operator', 'message' => __( 'The range operator (..) is not allowed in notification templates.' ) ];
        }

        if ( $this->loopDepth( $module ) > self::MAX_LOOP_DEPTH ) {
            $errors[] = [ 'field' => $field, 'code' => 'loop-too-deep', 'message' => __( 'Loops may be nested at most :depth deep in notification templates.', [ 'depth' => self::MAX_LOOP_DEPTH ] ) ];
        }

        $known = array_map( static fn ( string $path ): string => str_replace( '.*', '', $path ), $declared );

        foreach ( $references as [ $path, $line ] ) {
            if ( in_array( strtok( $path, '.' ), $locals, true ) || $this->isDeclared( $path, $known ) ) {
                continue;
            }

            $errors[ 'undeclared:' . $path ] = [
                'field'   => $field,
                'code'    => 'undeclared-variable',
                'message' => __( 'Line :line: ":variable" is not an available variable for this template.', [ 'line' => $line, 'variable' => $path ] ),
            ];
        }

        return array_values( $errors );
    }

    /**
     * Walks the AST, collecting variable paths (with their line), names
     * the template assigns itself (`for` / `set` targets), and whether the
     * range operator appears.
     *
     * @since 1.0.0
     *
     * @param  Node                                $node        Node.
     * @param  array<int, array{0: string, 1: int}>  $references  Collected references.
     * @param  array<int, string>                  $locals      Collected local names.
     * @param  bool                                $usesRange   Whether `..` appeared.
     *
     * @return void
     */
    protected function collect( Node $node, array &$references, array &$locals, bool &$usesRange ): void
    {
        if ( $node instanceof AssignNameExpression ) {
            $locals[] = (string) $node->getAttribute( 'name' );

            return;
        }

        if ( $node instanceof ForNode ) {
            $locals[] = 'loop';
        }

        if ( $node instanceof RangeBinary ) {
            $usesRange = true;
        }

        if ( $node instanceof GetAttrExpression ) {
            $path = $this->attributePath( $node );

            if ( null !== $path ) {
                $references[] = [ $path, $node->getTemplateLine() ];

                if ( $node->hasNode( 'arguments' ) ) {
                    $this->collect( $node->getNode( 'arguments' ), $references, $locals, $usesRange );
                }

                return;
            }
        }

        if ( $node instanceof ContextVariable ) {
            $references[] = [ (string) $node->getAttribute( 'name' ), $node->getTemplateLine() ];

            return;
        }

        foreach ( $node as $child ) {
            $this->collect( $child, $references, $locals, $usesRange );
        }
    }

    /**
     * The deepest `for` nesting under `$node`.
     *
     * @since 1.0.0
     *
     * @param  Node  $node  Node.
     *
     * @return int
     */
    protected function loopDepth( Node $node ): int
    {
        $deepest = 0;

        foreach ( $node as $child ) {
            $deepest = max( $deepest, $this->loopDepth( $child ) );
        }

        return $deepest + ( $node instanceof ForNode ? 1 : 0 );
    }

    /**
     * The dotted path of a chain of constant attribute lookups on a
     * variable (`Order.customer.name`, `Order.items[0].name` →
     * `Order.items.name`), or null when any link is dynamic.
     *
     * @since 1.0.0
     *
     * @param  GetAttrExpression  $node  Attribute lookup.
     *
     * @return string|null
     */
    protected function attributePath( GetAttrExpression $node ): ?string
    {
        $attribute = $node->getNode( 'attribute' );

        if ( ! $attribute instanceof ConstantExpression ) {
            return null;
        }

        $base = $node->getNode( 'node' );
        $name = $attribute->getAttribute( 'value' );

        $prefix = match ( true ) {
            $base instanceof GetAttrExpression                                                         => $this->attributePath( $base ),
            $base instanceof ContextVariable && ! $base instanceof AssignNameExpression                => (string) $base->getAttribute( 'name' ),
            default                                                                                    => null,
        };

        if ( null === $prefix ) {
            return null;
        }

        // List indexes (`items[0]`) address the element, like `*` in declarations.
        return is_int( $name ) || ctype_digit( (string) $name ) ? $prefix : $prefix . '.' . $name;
    }

    /**
     * Whether `$path` is declared, or leads to something declared
     * (`Order.customer` when `Order.customer.name` is declared).
     *
     * @since 1.0.0
     *
     * @param  string              $path   Referenced path.
     * @param  array<int, string>  $known  Declared paths with `*` segments removed.
     *
     * @return bool
     */
    protected function isDeclared( string $path, array $known ): bool
    {
        foreach ( $known as $declared ) {
            if ( $declared === $path || str_starts_with( $declared, $path . '.' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * An API field error for a Twig failure.
     *
     * @since 1.0.0
     *
     * @param  string     $field  Field name.
     * @param  TwigError  $error  Twig error.
     *
     * @return array{field: string, code: string, message: string}
     */
    protected function errorFor( string $field, TwigError $error ): array
    {
        $line = $error->getTemplateLine();

        return [
            'field'   => $field,
            'code'    => $error instanceof SecurityError ? 'forbidden' : 'template-error',
            'message' => $line > 0
                ? __( 'Line :line: :message', [ 'line' => $line, 'message' => $error->getRawMessage() ] )
                : $error->getRawMessage(),
        ];
    }
}
