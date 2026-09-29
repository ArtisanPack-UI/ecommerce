<?php

declare( strict_types=1 );

use Illuminate\Support\Facades\File;

beforeEach( function (): void {
    $this->tmp  = sys_get_temp_dir() . '/i18n-lint-' . uniqid();
    $this->src  = $this->tmp . '/src';
    $this->lang = $this->tmp . '/lang';

    File::ensureDirectoryExists( $this->src );
    File::copyDirectory( dirname( __DIR__, 3 ) . '/lang', $this->lang );
} );

afterEach( function (): void {
    File::deleteDirectory( $this->tmp );
} );

/**
 * Runs the lint over the engine plus the fixture directory, against the
 * fixture copy of the catalogues.
 */
function lintTranslations( array $options = [] ): Illuminate\Testing\PendingCommand
{
    return test()->artisan( 'ecommerce:lint:translations', array_merge( [
        '--path' => [ test()->src ],
        '--lang' => test()->lang,
    ], $options ) );
}

it( 'passes on the engine source and shipped catalogues', function (): void {
    $this->artisan( 'ecommerce:lint:translations' )
        ->expectsOutputToContain( 'Translation lint passed.' )
        ->assertExitCode( 0 );
} );

it( 'fails on a bare string in a sink', function ( string $code ): void {
    File::put( $this->src . '/Bad.php', "<?php\n{$code}\n" );

    lintTranslations()
        ->expectsOutputToContain( 'Bare user-facing string' )
        ->assertExitCode( 1 );
} )->with( [
    'problem detail'        => "return Problem::make( 422, 'nope', __( 'Nope' ), 'Something went wrong here.' );",
    'problem sprintf'       => "return Problem::make( 403, 'forbidden', __( 'Forbidden' ), sprintf( 'Missing ability %s.', \$a ) );",
    'user-facing exception' => "throw new CartOperationException( 'code', 'coupon-bad', 'That coupon is bad.' );",
    'abort'                 => "abort( 404, 'Nothing to see here.' );",
    'validation closure'    => "\$rule = function ( \$attribute, \$value, \$fail ) { \$fail( 'The value is not allowed.' ); };",
    'label key'             => "\$meta = [ 'label' => 'Flat rate shipping' ];",
    'messages method'       => "class R { public function messages(): array { return [ 'key.regex' => 'Keys must be kebab-case.' ]; } }",
    'exception constructor' => "class RefundNotAllowedException extends Exception { public function __construct() { parent::__construct( 'Refunds are closed.' ); } }",
    'interpolated string'   => 'throw new RefundNotAllowedException( "Refund for order {$id} is not allowed." );',
    'heredoc'               => "abort( 403, <<<TXT\nYou may not refund order {\$id}.\nTXT );",
    'nowdoc'                => "abort( 403, <<<'TXT'\nRefunds are closed for this order.\nTXT );",
] );

it( 'fails when a translation key is built by concatenation', function (): void {
    File::put( $this->src . '/Concat.php', "<?php\n\$label = __( 'Free ' . 'shipping' );\n" );

    lintTranslations()
        ->expectsOutputToContain( 'Translation key must be a single string literal' )
        ->doesntExpectOutputToContain( 'Missing translation [en]: \"Free \"' )
        ->assertExitCode( 1 );
} );

it( 'passes interpolated strings that are not prose', function (): void {
    File::put( $this->src . '/Slug.php', "<?php\n\$meta = [ 'label' => __( 'Tax' ), 'icon' => \"hero-{\$icon}\" ];\n" );

    lintTranslations()->assertExitCode( 0 );
} );

it( 'passes identifiers, slugs, and translated strings in sinks', function (): void {
    File::put( $this->src . '/Good.php', <<<'PHP'
<?php
return Problem::make( 422, 'coupon-invalid', __( 'Invalid list query' ), __( 'Missing ability :ability.', [ 'ability' => 'ecommerce.order.view' ] ) );
$meta = [ 'label' => __( 'Flat rate' ), 'icon' => 'hero-cube', 'title' => 'String!' ];
Log::warning( 'This is a developer-facing log message.' );
throw new InvalidArgumentException( 'Registry key must not be empty.' );
PHP );

    lintTranslations()->assertExitCode( 0 );
} );

it( 'skips a sink annotated with i18n-lint:ignore and a reason', function (): void {
    File::put( $this->src . '/Ignored.php', <<<'PHP'
<?php
$sample = [
    // i18n-lint:ignore reason:sample store data
    'label' => 'Notes on the Engine (PDF)',
    'title' => 'Beautifully written', // i18n-lint:ignore reason:sample review
];
PHP );

    lintTranslations()
        ->expectsOutputToContain( 'i18n-lint:ignore' )
        ->assertExitCode( 0 );
} );

it( 'does not accept an ignore annotation without a reason', function (): void {
    File::put( $this->src . '/NoReason.php', "<?php\n\$m = [ 'label' => 'Flat rate shipping' ]; // i18n-lint:ignore\n" );

    lintTranslations()->assertExitCode( 1 );
} );

it( 'does not scan documentation paths for description keys', function (): void {
    File::ensureDirectoryExists( $this->src . '/OpenApi' );
    File::put( $this->src . '/OpenApi/Doc.php', "<?php\n\$s = [ 'description' => 'An amount in minor units.' ];\n" );

    lintTranslations()->assertExitCode( 0 );
} );

it( 'fails when a key is missing from a catalogue', function (): void {
    File::put( $this->src . '/NewKey.php', "<?php\necho __( 'A brand new sentence.' );\n" );

    lintTranslations()
        ->expectsOutputToContain( 'Missing translation [en]: "A brand new sentence."' )
        ->expectsOutputToContain( 'Missing translation [de]: "A brand new sentence."' )
        ->assertExitCode( 1 );
} );

it( 'syncs missing keys into en.json and keeps failing until the other locales are translated', function (): void {
    File::put( $this->src . '/NewKey.php', "<?php\necho trans_choice( ':count widget|:count widgets', 2 );\n" );

    lintTranslations( [ '--sync' => true ] )
        ->expectsOutputToContain( 'Synced 1 new key(s) into en.json.' )
        ->expectsOutputToContain( 'Missing translation [es]' )
        ->assertExitCode( 1 );

    $en = json_decode( (string) file_get_contents( $this->lang . '/en.json' ), true );

    expect( $en )->toHaveKey( ':count widget|:count widgets', ':count widget|:count widgets' )
        ->and( array_keys( $en ) )->toBe( collect( array_keys( $en ) )->sort( SORT_STRING )->values()->all() );

    foreach ( [ 'es', 'fr', 'de' ] as $locale ) {
        $catalogue                                 = json_decode( (string) file_get_contents( $this->lang . "/{$locale}.json" ), true );
        $catalogue[':count widget|:count widgets'] = 'x|y';
        file_put_contents( $this->lang . "/{$locale}.json", json_encode( $catalogue ) );
    }

    lintTranslations()->assertExitCode( 0 );
} );
