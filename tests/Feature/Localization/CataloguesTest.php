<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Console\Commands\LintTranslationsCommand;
use Symfony\Component\Finder\Finder;

/**
 * Decoded catalogue for `$locale`.
 *
 * @return array<string, string>
 */
function catalogue( string $locale ): array
{
    return json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . "/lang/{$locale}.json" ), true, flags: JSON_THROW_ON_ERROR );
}

/**
 * `:placeholders` outside Twig, and Twig `{{ … }}` expressions, in `$value`.
 *
 * @return array<int, array<int, string>>
 */
function placeholdersOf( string $value ): array
{
    preg_match_all( '/:[A-Za-z_]+/', (string) preg_replace( '/\{\{.*?\}\}|\{%.*?%\}/s', '', $value ), $colon );
    preg_match_all( '/\{\{.*?\}\}/s', $value, $twig );

    return [
        collect( $colon[0] )->unique()->sort()->values()->all(),
        collect( $twig[0] )->unique()->sort()->values()->all(),
    ];
}

it( 'ships a catalogue entry for every key used in src/', function (): void {
    $command = app( LintTranslationsCommand::class );
    $keys    = [];

    foreach ( ( new Finder() )->files()->in( dirname( __DIR__, 3 ) . '/src' )->name( '*.php' ) as $file ) {
        array_push( $keys, ...$command->extractKeys( (string) file_get_contents( $file->getRealPath() ) ) );
    }

    foreach ( LintTranslationsCommand::LOCALES as $locale ) {
        expect( array_diff( array_unique( $keys ), array_keys( catalogue( $locale ) ) ) )->toBe( [] );
    }
} );

it( 'ships identical key sets in every locale', function (): void {
    $en = array_keys( catalogue( 'en' ) );

    foreach ( [ 'es', 'fr', 'de' ] as $locale ) {
        expect( array_keys( catalogue( $locale ) ) )->toBe( $en );
    }
} );

it( 'keeps English as its own translation', function (): void {
    foreach ( catalogue( 'en' ) as $key => $value ) {
        expect( $value )->toBe( $key );
    }
} );

it( 'preserves placeholders, Twig expressions, and plural segments in every translation', function ( string $locale ): void {
    foreach ( catalogue( $locale ) as $key => $value ) {
        expect( $value )->not->toBe( '' )
            ->and( placeholdersOf( $value ) )->toBe( placeholdersOf( $key ), "{$locale}: {$key}" )
            ->and( substr_count( $value, '|' ) )->toBe( substr_count( $key, '|' ), "{$locale}: {$key}" );
    }
} )->with( [ 'es', 'fr', 'de' ] );

it( 'resolves engine strings in each shipped locale', function ( string $locale, string $expected ): void {
    app()->setLocale( $locale );

    expect( __( 'That coupon code is not valid.' ) )->toBe( $expected );
} )->with( [
    'en' => [ 'en', 'That coupon code is not valid.' ],
    'es' => [ 'es', 'Ese código de cupón no es válido.' ],
    'fr' => [ 'fr', 'Ce code promo n’est pas valide.' ],
    'de' => [ 'de', 'Dieser Gutscheincode ist ungültig.' ],
] );

it( 'pluralizes in each locale', function (): void {
    app()->setLocale( 'es' );

    expect( trans_choice( ':count item|:count items', 1, [ 'count' => 1 ] ) )->toBe( '1 artículo' )
        ->and( trans_choice( ':count item|:count items', 3, [ 'count' => 3 ] ) )->toBe( '3 artículos' );
} );
