<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\CurrencyRateProvider;
use ArtisanPackUI\Ecommerce\CurrencyRates\ConfigRateProvider;
use ArtisanPackUI\Ecommerce\CurrencyRates\FrankfurterRateProvider;
use ArtisanPackUI\Ecommerce\Registries\CurrencyRateProviderRegistry;
use Money\Currency;

it( 'boots with config and frankfurter registered', function (): void {
    $registry = $this->app->make( CurrencyRateProviderRegistry::class );

    expect( $registry->has( 'config' ) )->toBeTrue();
    expect( $registry->has( 'frankfurter' ) )->toBeTrue();

    expect( $registry->get( 'config' ) )->toBeInstanceOf( ConfigRateProvider::class );
    expect( $registry->get( 'frankfurter' ) )->toBeInstanceOf( FrankfurterRateProvider::class );
} );

it( 'throws when resolving an unknown key', function (): void {
    $registry = $this->app->make( CurrencyRateProviderRegistry::class );

    expect( fn () => $registry->get( 'nope' ) )->toThrow( RuntimeException::class );
} );

it( 'resolves the active provider named by config', function (): void {
    config()->set( 'artisanpack.ecommerce.currency.provider', 'frankfurter' );

    $registry = $this->app->make( CurrencyRateProviderRegistry::class );

    expect( $registry->active() )->toBeInstanceOf( FrankfurterRateProvider::class );
} );

it( 'rejects an empty key', function (): void {
    $registry = $this->app->make( CurrencyRateProviderRegistry::class );

    expect( fn () => $registry->register( '   ', ConfigRateProvider::class ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'rejects a class name that is not a CurrencyRateProvider', function (): void {
    $registry = $this->app->make( CurrencyRateProviderRegistry::class );

    expect( fn () => $registry->register( 'bogus', stdClass::class ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'throws on double registration in testing environment', function (): void {
    $registry = $this->app->make( CurrencyRateProviderRegistry::class );

    // `config` is already registered from boot.
    expect( fn () => $registry->register( 'config', ConfigRateProvider::class ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'accepts a pre-built instance and returns it verbatim', function (): void {
    $registry = $this->app->make( CurrencyRateProviderRegistry::class );

    $instance = new class implements CurrencyRateProvider {
        public function key(): string
        {
            return 'stub';
        }

        public function getRateE8( Currency $from, Currency $to ): int
        {
            return 100_000_000;
        }
    };

    $registry->register( 'stub', $instance );

    expect( $registry->get( 'stub' ) )->toBe( $instance );
} );
