<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\CurrencyRates\ConfigRateProvider;
use Illuminate\Config\Repository;
use Money\Currency;

if ( ! function_exists( 'makeConfigRateProvider' ) ) {
    /**
     * Builds a ConfigRateProvider seeded with the given rate table.
     *
     * @param  array<string, array<string, int|string>>  $rates  Rate table.
     */
    function makeConfigRateProvider( array $rates ): ConfigRateProvider
    {
        return new ConfigRateProvider( new Repository( [
            'artisanpack' => [
                'ecommerce' => [
                    'currency' => [
                        'rates' => $rates,
                    ],
                ],
            ],
        ] ) );
    }
}

it( 'returns 10^8 for the identity rate', function (): void {
    $provider = makeConfigRateProvider( [] );

    expect( $provider->getRateE8( new Currency( 'USD' ), new Currency( 'USD' ) ) )
        ->toBe( 100_000_000 );
} );

it( 'returns the configured rate verbatim as an int', function (): void {
    $provider = makeConfigRateProvider( [
        'USD' => [ 'EUR' => 92_500_000 ],
    ] );

    expect( $provider->getRateE8( new Currency( 'USD' ), new Currency( 'EUR' ) ) )
        ->toBe( 92_500_000 );
} );

it( 'accepts an integer-string rate value', function (): void {
    $provider = makeConfigRateProvider( [
        'USD' => [ 'GBP' => '79000000' ],
    ] );

    expect( $provider->getRateE8( new Currency( 'USD' ), new Currency( 'GBP' ) ) )
        ->toBe( 79_000_000 );
} );

it( 'computes the inverse rate when only the reverse pair is configured', function (): void {
    // 1 USD -> 0.925 EUR means 1 EUR -> 1/0.925 ≈ 1.08108108... USD
    $provider = makeConfigRateProvider( [
        'USD' => [ 'EUR' => 92_500_000 ],
    ] );

    $rate = $provider->getRateE8( new Currency( 'EUR' ), new Currency( 'USD' ) );

    // 10^16 / 92500000 = 108108108.10... rounded half-up = 108108108
    expect( $rate )->toBe( 108_108_108 );
} );

it( 'throws when neither direction is configured', function (): void {
    $provider = makeConfigRateProvider( [] );

    expect( fn () => $provider->getRateE8( new Currency( 'USD' ), new Currency( 'EUR' ) ) )
        ->toThrow( RuntimeException::class );
} );

it( 'rejects a non-integer rate value', function (): void {
    $provider = makeConfigRateProvider( [
        'USD' => [ 'EUR' => '0.925' ],
    ] );

    expect( fn () => $provider->getRateE8( new Currency( 'USD' ), new Currency( 'EUR' ) ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'rejects a non-positive rate', function (): void {
    $provider = makeConfigRateProvider( [
        'USD' => [ 'EUR' => 0 ],
    ] );

    expect( fn () => $provider->getRateE8( new Currency( 'USD' ), new Currency( 'EUR' ) ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'normalises currency codes to uppercase before lookup', function (): void {
    $provider = makeConfigRateProvider( [
        'USD' => [ 'EUR' => 92_500_000 ],
    ] );

    // Money\Currency stores the code verbatim, so pass lowercase in.
    expect( $provider->getRateE8( new Currency( 'usd' ), new Currency( 'eur' ) ) )
        ->toBe( 92_500_000 );
} );

it( 'reports its registry key', function (): void {
    expect( ( makeConfigRateProvider( [] ) )->key() )->toBe( 'config' );
} );
