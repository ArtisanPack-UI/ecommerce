<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\ValueObjects\Currency;
use Money\Currency as MoneyCurrency;

it( 'accepts a valid ISO 4217 code', function (): void {
    $currency = new Currency( 'USD' );

    expect( $currency->code() )->toBe( 'USD' );
    expect( (string) $currency )->toBe( 'USD' );
} );

it( 'normalises the code to uppercase', function (): void {
    expect( Currency::of( 'usd' )->code() )->toBe( 'USD' );
    expect( Currency::of( ' Eur ' )->code() )->toBe( 'EUR' );
} );

it( 'rejects codes that are not three A–Z letters', function ( string $code ): void {
    expect( fn () => new Currency( $code ) )->toThrow( InvalidArgumentException::class );
} )->with( [
    'empty'      => '',
    'two chars'  => 'US',
    'four chars' => 'USDD',
    'digits'     => 'US1',
    'symbols'    => '$$$',
] );

it( 'compares two currencies for equality', function (): void {
    expect( Currency::of( 'USD' )->equals( Currency::of( 'usd' ) ) )->toBeTrue();
    expect( Currency::of( 'USD' )->equals( Currency::of( 'EUR' ) ) )->toBeFalse();
} );

it( 'converts to a moneyphp currency instance', function (): void {
    $mp = Currency::of( 'USD' )->toMoneyPhp();

    expect( $mp )->toBeInstanceOf( MoneyCurrency::class );
    expect( $mp->getCode() )->toBe( 'USD' );
} );

it( 'json-serialises to the currency code string', function (): void {
    expect( json_encode( Currency::of( 'USD' ) ) )->toBe( '"USD"' );
} );
