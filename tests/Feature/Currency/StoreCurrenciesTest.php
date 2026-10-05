<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\CurrencyResolver;
use ArtisanPackUI\Ecommerce\CurrencyRates\FrankfurterRateProvider;
use ArtisanPackUI\Ecommerce\Services\SessionCurrencyResolver;
use ArtisanPackUI\Ecommerce\Services\StoreCurrencies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Money\Currency;

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.base_currency', 'USD' );
    config()->set( 'artisanpack.ecommerce.currency.enabled', [ 'eur', 'GBP', 'USD', 'XXZ', '' ] );
} );

it( 'lists the base currency first, then the valid enabled ones', function (): void {
    $currencies = app( StoreCurrencies::class );

    expect( $currencies->enabled() )->toBe( [ 'USD', 'EUR', 'GBP' ] )
        ->and( $currencies->isEnabled( 'gbp' ) )->toBeTrue()
        ->and( $currencies->isEnabled( 'JPY' ) )->toBeFalse();

    config()->set( 'artisanpack.ecommerce.currency.enabled', 'CAD, AUD' );

    expect( $currencies->enabled() )->toBe( [ 'USD', 'CAD', 'AUD' ] );
} );

it( 'is editable as the currency.enabled setting', function (): void {
    expect( app( ArtisanPackUI\Ecommerce\Registries\SettingsRegistry::class )->has( 'currency.enabled' ) )->toBeTrue();
} );

it( 'resolves the shopper\'s choice from the session, cookie, or header, else the base currency', function (): void {
    $resolver = app( CurrencyResolver::class );

    expect( $resolver )->toBeInstanceOf( SessionCurrencyResolver::class )
        ->and( $resolver->resolve( Request::create( '/' ) ) )->toBe( 'USD' );

    $header = Request::create( '/', server: [ 'HTTP_X_CURRENCY' => 'gbp' ] );
    expect( $resolver->resolve( $header ) )->toBe( 'GBP' );

    $cookie = Request::create( '/', cookies: [ 'ecommerce_currency' => 'EUR' ] );
    expect( $resolver->resolve( $cookie ) )->toBe( 'EUR' );

    $session = Request::create( '/', server: [ 'HTTP_X_CURRENCY' => 'GBP' ] );
    $session->setLaravelSession( app( 'session' )->driver( 'array' ) );
    expect( $resolver->remember( $session, 'eur' ) )->toBe( 'EUR' )
        ->and( $resolver->resolve( $session ) )->toBe( 'EUR' );

    // A currency the store doesn't sell in is ignored.
    expect( $resolver->resolve( Request::create( '/', server: [ 'HTTP_X_CURRENCY' => 'JPY' ] ) ) )->toBe( 'USD' )
        ->and( $resolver->remember( $session, 'JPY' ) )->toBe( 'USD' );
} );

it( 'lets a geo satellite choose through the currency.resolved filter', function (): void {
    addFilter( 'ap.ecommerce.currency.resolved', static fn ( string $currency, Request $request ): string => 'DE' === $request->header( 'CF-IPCountry' ) ? 'EUR' : $currency );

    expect( app( CurrencyResolver::class )->resolve( Request::create( '/', server: [ 'HTTP_CF_IPCOUNTRY' => 'DE' ] ) ) )->toBe( 'EUR' );

    addFilter( 'ap.ecommerce.currency.resolved', static fn (): string => 'JPY', 20 );

    expect( app( CurrencyResolver::class )->resolve( Request::create( '/' ) ) )->toBe( 'USD' );
} );

it( 'refreshes and caches the rate to every enabled currency', function (): void {
    Cache::flush();
    config()->set( 'artisanpack.ecommerce.currency.provider', FrankfurterRateProvider::KEY );
    Http::fake( [
        'api.frankfurter.dev/*symbols=EUR*' => Http::response( [ 'rates' => [ 'EUR' => 0.925 ] ] ),
        'api.frankfurter.dev/*symbols=GBP*' => Http::response( [ 'rates' => [ 'GBP' => 0.79 ] ] ),
    ] );

    $this->artisan( 'ecommerce:refresh-fx-rates' )
        ->expectsOutputToContain( 'Refreshed 2 rate(s)' )
        ->assertSuccessful();

    // Warm: no further HTTP calls for today's rates.
    expect( app( FrankfurterRateProvider::class )->getRateE8( new Currency( 'USD' ), new Currency( 'GBP' ) ) )->toBe( 79_000_000 );
    Http::assertSentCount( 2 );
} );

it( 'fails when a rate cannot be refreshed', function (): void {
    Cache::flush();
    config()->set( 'artisanpack.ecommerce.currency.provider', FrankfurterRateProvider::KEY );
    Http::fake( [ 'api.frankfurter.dev/*' => Http::response( 'down', 503 ) ] );

    $this->artisan( 'ecommerce:refresh-fx-rates', [ '--currency' => [ 'EUR' ] ] )
        ->expectsOutputToContain( '1 of 1 rate(s) could not be refreshed' )
        ->assertFailed();
} );
