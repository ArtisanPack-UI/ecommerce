<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Support\LocalizedDate;
use ArtisanPackUI\Ecommerce\Support\RegionalJsonFallbackLoader;
use ArtisanPackUI\Ecommerce\Support\TaxLabel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

it( 'falls back from a regional locale to its base catalogue', function ( string $locale, string $expected ): void {
    expect( __( 'Tax', [], $locale ) )->toBe( $expected )
        ->and( TaxLabel::for( $locale ) )->toBe( $expected );
} )->with( [
    'de_DE' => [ 'de_DE', 'USt.' ],
    'es_MX' => [ 'es_MX', 'IVA' ],
    'fr_CA' => [ 'fr_CA', 'TVA' ],
    'de-AT' => [ 'de-AT', 'USt.' ],
] );

it( 'uses the base language date pattern for a regional locale', function (): void {
    expect( LocalizedDate::format( Carbon::parse( '2026-10-03' ), null, 'de_DE' ) )->toBe( '3. Oktober 2026' );
} );

it( 'wraps the translation loader and resolves base locales', function (): void {
    expect( app( 'translator' )->getLoader() )->toBeInstanceOf( RegionalJsonFallbackLoader::class )
        ->and( RegionalJsonFallbackLoader::baseLocale( 'de_DE' ) )->toBe( 'de' )
        ->and( RegionalJsonFallbackLoader::baseLocale( 'pt-BR' ) )->toBe( 'pt' )
        ->and( RegionalJsonFallbackLoader::baseLocale( 'de' ) )->toBeNull();
} );

it( 'lets a regional catalogue override its base language key by key', function (): void {
    $dir = sys_get_temp_dir() . '/ecommerce-regional-' . uniqid();
    File::ensureDirectoryExists( $dir );
    File::put( $dir . '/de_AT.json', json_encode( [ 'Tax' => 'MwSt.' ] ) );

    app( 'translator' )->addJsonPath( $dir );

    try {
        expect( __( 'Tax', [], 'de_AT' ) )->toBe( 'MwSt.' )
            ->and( __( 'Forbidden', [], 'de_AT' ) )->toBe( __( 'Forbidden', [], 'de' ) );
    } finally {
        File::deleteDirectory( $dir );
    }
} );

it( 'prefers a published catalogue in lang/vendor/ecommerce over the shipped one', function (): void {
    // A key no other test asserts in German: the published file lives in the
    // shared testbench app, so a parallel run's other tests can see it.
    $key       = 'A product needs a name.';
    $published = app()->langPath( 'vendor/ecommerce' );
    $file      = $published . '/de.json';
    $original  = is_file( $file ) ? File::get( $file ) : null;

    File::ensureDirectoryExists( $published );
    File::put( $file, json_encode( array_merge(
        null === $original ? [] : (array) json_decode( $original, true ),
        [ $key => 'Ein Produkt braucht einen Namen. (veröffentlicht)' ],
    ) ) );

    try {
        app( 'translator' )->setLoaded( [] );

        expect( __( $key, [], 'de' ) )->toBe( 'Ein Produkt braucht einen Namen. (veröffentlicht)' )
            ->and( __( 'Forbidden', [], 'de' ) )->not->toBe( 'Forbidden' );
    } finally {
        // Put back a catalogue that was already there, and remove only what
        // this test created: another process may be using the directories.
        if ( null !== $original ) {
            File::put( $file, $original );
        } else {
            File::delete( $file );
        }

        foreach ( [ $published, app()->langPath( 'vendor' ) ] as $dir ) {
            if ( is_dir( $dir ) && [] === File::files( $dir ) && [] === File::directories( $dir ) ) {
                @rmdir( $dir );
            }
        }

        app( 'translator' )->setLoaded( [] );
    }
} );
