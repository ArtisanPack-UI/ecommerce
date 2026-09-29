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
    $published = app()->langPath( 'vendor/ecommerce' );
    $created   = ! is_dir( $published );

    File::ensureDirectoryExists( $published );
    File::put( $published . '/de.json', json_encode( [ 'Tax' => 'MwSt. (published)' ] ) );

    try {
        app( 'translator' )->setLoaded( [] );

        expect( __( 'Tax', [], 'de' ) )->toBe( 'MwSt. (published)' )
            ->and( __( 'Forbidden', [], 'de' ) )->not->toBe( 'Forbidden' );
    } finally {
        File::delete( $published . '/de.json' );

        if ( $created ) {
            File::deleteDirectory( app()->langPath( 'vendor' ) );
        }
    }
} );
