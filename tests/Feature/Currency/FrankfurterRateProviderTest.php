<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\CurrencyRates\FrankfurterRateProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Money\Currency;

beforeEach( function (): void {
    Cache::flush();
    config()->set( 'artisanpack.ecommerce.currency.frankfurter.base_url', 'https://api.frankfurter.dev/v1' );
} );

it( 'returns 10^8 for identity without hitting the API', function (): void {
    Http::fake();

    $rate = app( FrankfurterRateProvider::class )->getRateE8( new Currency( 'USD' ), new Currency( 'USD' ) );

    expect( $rate )->toBe( 100_000_000 );
    Http::assertNothingSent();
} );

it( 'fetches the rate from the API and converts to E8', function (): void {
    Http::fake( [
        'api.frankfurter.dev/*' => Http::response( [
            'base'  => 'USD',
            'date'  => '2026-09-14',
            'rates' => [ 'EUR' => 0.925 ],
        ] ),
    ] );

    $rate = app( FrankfurterRateProvider::class )->getRateE8( new Currency( 'USD' ), new Currency( 'EUR' ) );

    expect( $rate )->toBe( 92_500_000 );

    Http::assertSent( function ( $request ): bool {
        return 'https://api.frankfurter.dev/v1/latest?base=USD&symbols=EUR' === $request->url();
    } );
} );

it( 'caches the fetched rate for subsequent lookups on the same day', function (): void {
    Http::fake( [
        'api.frankfurter.dev/*' => Http::response( [
            'rates' => [ 'GBP' => 0.79 ],
        ] ),
    ] );

    $provider = app( FrankfurterRateProvider::class );

    $first  = $provider->getRateE8( new Currency( 'USD' ), new Currency( 'GBP' ) );
    $second = $provider->getRateE8( new Currency( 'USD' ), new Currency( 'GBP' ) );

    expect( $first )->toBe( 79_000_000 );
    expect( $second )->toBe( 79_000_000 );

    Http::assertSentCount( 1 );
} );

it( 'throws when the upstream returns a non-2xx response', function (): void {
    Http::fake( [
        'api.frankfurter.dev/*' => Http::response( 'oops', 502 ),
    ] );

    expect( fn () => app( FrankfurterRateProvider::class )->getRateE8( new Currency( 'USD' ), new Currency( 'EUR' ) ) )
        ->toThrow( RuntimeException::class );
} );

it( 'throws when the response is missing the expected rate key', function (): void {
    Http::fake( [
        'api.frankfurter.dev/*' => Http::response( [
            'rates' => [ 'JPY' => 148.5 ],
        ] ),
    ] );

    expect( fn () => app( FrankfurterRateProvider::class )->getRateE8( new Currency( 'USD' ), new Currency( 'EUR' ) ) )
        ->toThrow( RuntimeException::class );
} );

it( 'throws when the response rate is non-numeric', function (): void {
    Http::fake( [
        'api.frankfurter.dev/*' => Http::response( [
            'rates' => [ 'EUR' => 'not-a-number' ],
        ] ),
    ] );

    expect( fn () => app( FrankfurterRateProvider::class )->getRateE8( new Currency( 'USD' ), new Currency( 'EUR' ) ) )
        ->toThrow( RuntimeException::class );
} );

it( 'handles rates greater than one', function (): void {
    // e.g. 1 EUR → 148.5 JPY.
    Http::fake( [
        'api.frankfurter.dev/*' => Http::response( [
            'rates' => [ 'JPY' => 148.5 ],
        ] ),
    ] );

    $rate = app( FrankfurterRateProvider::class )->getRateE8( new Currency( 'EUR' ), new Currency( 'JPY' ) );

    expect( $rate )->toBe( 14_850_000_000 );
} );

it( 'converts rates below 0.0001 without scientific notation', function ( float $raw, int $e8 ): void {
    Http::fake( [ 'api.frankfurter.dev/*' => Http::response( [ 'rates' => [ 'USD' => $raw ] ] ) ] );

    expect( app( FrankfurterRateProvider::class )->getRateE8( new Currency( 'IDR' ), new Currency( 'USD' ) ) )->toBe( $e8 );
} )->with( [
    'IDR → USD'   => [ 0.000061, 6_100 ],
    'VND → USD'   => [ 0.0000393, 3_930 ],
    'rounds up'   => [ 0.000000015, 2 ],
    'large rates' => [ 16_250.5, 1_625_050_000_000 ],
] );

it( 'falls back to the last good rate when Frankfurter fails on a later day', function (): void {
    Http::fake( [ 'api.frankfurter.dev/*' => Http::sequence()->push( [ 'rates' => [ 'EUR' => 0.925 ] ] )->push( 'down', 500 )->push( 'down', 500 ) ] );
    $provider = app( FrankfurterRateProvider::class );

    expect( $provider->getRateE8( new Currency( 'USD' ), new Currency( 'EUR' ) ) )->toBe( 92_500_000 );

    $this->travel( 1 )->days();

    expect( $provider->getRateE8( new Currency( 'USD' ), new Currency( 'EUR' ) ) )->toBe( 92_500_000 );

    // The fallback is cached briefly, so the next lookup doesn't call out.
    expect( $provider->getRateE8( new Currency( 'USD' ), new Currency( 'EUR' ) ) )->toBe( 92_500_000 );
    Http::assertSentCount( 2 );
} );

it( 'still throws when there is no rate to fall back to', function (): void {
    Http::fake( [ 'api.frankfurter.dev/*' => Http::response( 'down', 500 ) ] );

    app( FrankfurterRateProvider::class )->getRateE8( new Currency( 'USD' ), new Currency( 'JPY' ) );
} )->throws( RuntimeException::class, 'HTTP 500' );
