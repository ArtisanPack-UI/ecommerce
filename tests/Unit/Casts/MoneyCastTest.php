<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Casts\MoneyCast;
use ArtisanPackUI\Ecommerce\ValueObjects\Currency as CurrencyVO;
use Money\Currency;
use Money\Money;

it( 'hydrates paired amount + currency columns into a Money instance', function (): void {
    $cast = new MoneyCast();

    $money = $cast->get( makeAnonymousModel(), 'subtotal', null, [
        'subtotal_amount'   => 12_345,
        'subtotal_currency' => 'USD',
    ] );

    expect( $money )->toBeInstanceOf( Money::class );
    expect( $money->getAmount() )->toBe( '12345' );
    expect( $money->getCurrency()->getCode() )->toBe( 'USD' );
} );

it( 'returns null when either paired column is null', function ( array $attributes ): void {
    $cast = new MoneyCast();

    expect( $cast->get( makeAnonymousModel(), 'total', null, $attributes ) )->toBeNull();
} )->with( [
    'null amount'   => [[ 'total_amount' => null, 'total_currency' => 'USD' ]],
    'null currency' => [[ 'total_amount' => 100,  'total_currency' => null ]],
    'missing pair'  => [[]],
] );

it( 'accepts explicit column overrides', function (): void {
    $cast = new MoneyCast( 'grand_total_cents', 'grand_total_iso' );

    $money = $cast->get( makeAnonymousModel(), 'grand_total', null, [
        'grand_total_cents' => 999,
        'grand_total_iso'   => 'eur',
    ] );

    expect( $money->getAmount() )->toBe( '999' );
    expect( $money->getCurrency()->getCode() )->toBe( 'EUR' );
} );

it( 'splits a Money instance back into paired columns', function (): void {
    $cast = new MoneyCast();

    $out = $cast->set( makeAnonymousModel(), 'subtotal', new Money( 4_200, new Currency( 'USD' ) ), [] );

    expect( $out )->toBe( [
        'subtotal_amount'   => 4_200,
        'subtotal_currency' => 'USD',
    ] );
} );

it( 'accepts an [amount, currency] tuple', function (): void {
    $cast = new MoneyCast();

    $out = $cast->set( makeAnonymousModel(), 'discount', [ -250, 'USD' ], [] );

    expect( $out )->toBe( [
        'discount_amount'   => -250,
        'discount_currency' => 'USD',
    ] );
} );

it( 'accepts an associative [amount => .., currency => ..] array', function (): void {
    $cast = new MoneyCast();

    $out = $cast->set( makeAnonymousModel(), 'shipping', [ 'amount' => 599, 'currency' => 'eur' ], [] );

    expect( $out )->toBe( [
        'shipping_amount'   => 599,
        'shipping_currency' => 'EUR',
    ] );
} );

it( 'accepts a Currency value object on the write side', function (): void {
    $cast = new MoneyCast();

    $out = $cast->set( makeAnonymousModel(), 'tax', [ 'amount' => 800, 'currency' => CurrencyVO::of( 'CAD' ) ], [] );

    expect( $out )->toBe( [
        'tax_amount'   => 800,
        'tax_currency' => 'CAD',
    ] );
} );

it( 'clears both columns when writing null', function (): void {
    $cast = new MoneyCast();

    $out = $cast->set( makeAnonymousModel(), 'total', null, [] );

    expect( $out )->toBe( [
        'total_amount'   => null,
        'total_currency' => null,
    ] );
} );

it( 'round-trips a fluent Money chain through the cast', function (): void {
    $cast = new MoneyCast();

    $price   = new Money( 199, new Currency( 'USD' ) );
    $doubled = $price->multiply( 2 )->add( new Money( 1, new Currency( 'USD' ) ) );

    $columns  = $cast->set( makeAnonymousModel(), 'price', $doubled, [] );
    $hydrated = $cast->get( makeAnonymousModel(), 'price', null, $columns );

    expect( $hydrated->equals( $doubled ) )->toBeTrue();
    expect( $hydrated->getAmount() )->toBe( '399' );
    expect( $hydrated->getCurrency()->getCode() )->toBe( 'USD' );
} );

it( 'preserves signed negative amounts (refunds, credits, chargebacks)', function (): void {
    $cast = new MoneyCast();

    $refund  = new Money( -1_500, new Currency( 'USD' ) );
    $columns = $cast->set( makeAnonymousModel(), 'refund', $refund, [] );

    expect( $columns[ 'refund_amount' ] )->toBe( -1_500 );

    $hydrated = $cast->get( makeAnonymousModel(), 'refund', null, $columns );

    expect( $hydrated->getAmount() )->toBe( '-1500' );
    expect( $hydrated->isNegative() )->toBeTrue();
} );

it( 'refuses to accept floats on the write side', function (): void {
    $cast = new MoneyCast();

    // moneyphp itself rejects a float amount, but the cast's other input
    // shapes bypass that guard — verify the cast enforces the same rule.
    expect( fn () => $cast->set( makeAnonymousModel(), 'price', [ 'amount' => 0.1 + 0.2, 'currency' => 'USD' ], [] ) )
        ->toThrow( InvalidArgumentException::class );

    expect( fn () => $cast->set( makeAnonymousModel(), 'price', [ 1.5, 'USD' ], [] ) )
        ->toThrow( InvalidArgumentException::class );

    expect( fn () => $cast->set( makeAnonymousModel(), 'price', [ 'amount' => 199.99, 'currency' => 'USD' ], [] ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'refuses to hydrate a float amount from the database', function (): void {
    $cast = new MoneyCast();

    expect( fn () => $cast->get( makeAnonymousModel(), 'price', null, [
        'price_amount'   => 1.99,
        'price_currency' => 'USD',
    ] ) )->toThrow( InvalidArgumentException::class );
} );

it( 'rejects unrecognised values on the write side', function (): void {
    $cast = new MoneyCast();

    expect( fn () => $cast->set( makeAnonymousModel(), 'price', 'nope', [] ) )
        ->toThrow( InvalidArgumentException::class );

    expect( fn () => $cast->set( makeAnonymousModel(), 'price', [ 'amount' => 1 ], [] ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'rejects invalid currency codes on the write side', function (): void {
    $cast = new MoneyCast();

    expect( fn () => $cast->set( makeAnonymousModel(), 'price', [ 'amount' => 100, 'currency' => 'XX' ], [] ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'rejects a Money amount that overflows signed 64-bit BIGINT range', function (): void {
    $cast = new MoneyCast();

    // Money accepts arbitrary-precision numeric strings; multiplying a big
    // enough amount produces a getAmount() past PHP_INT_MAX. Persisting it
    // via `(int) $amount` would silently truncate, so the cast must throw.
    $overflowed = ( new Money( PHP_INT_MAX, new Currency( 'USD' ) ) )->multiply( 1_000 );

    expect( fn () => $cast->set( makeAnonymousModel(), 'price', $overflowed, [] ) )
        ->toThrow(
            InvalidArgumentException::class,
            'exceeds the signed 64-bit range',
        );
} );

it( 'rejects a hydrated amount that overflows signed 64-bit BIGINT range', function (): void {
    $cast = new MoneyCast();

    expect( fn () => $cast->get( makeAnonymousModel(), 'price', null, [
        'price_amount'   => '99999999999999999999',
        'price_currency' => 'USD',
    ] ) )->toThrow( InvalidArgumentException::class );
} );

it( 'rejects a partial column override', function (): void {
    // Mixing an explicit column name with a default silently paired against
    // a `{key}_amount` / `{key}_currency` default corrupts the pair. Force
    // callers to pass both overrides or neither.
    expect( fn () => new MoneyCast( 'price_cents' ) )
        ->toThrow( InvalidArgumentException::class );

    expect( fn () => new MoneyCast( null, 'price_iso' ) )
        ->toThrow( InvalidArgumentException::class );
} );
