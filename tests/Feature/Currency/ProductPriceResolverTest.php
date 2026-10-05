<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\CurrencyRateProvider;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Registries\CurrencyRateProviderRegistry;
use ArtisanPackUI\Ecommerce\Services\ProductPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Money\Currency;

uses( RefreshDatabase::class );

it( 'returns the explicit per-currency row verbatim', function (): void {
    $product = Product::factory()->simple()->create();
    ProductPrice::factory()
        ->forPriceable( $product )
        ->create( [ 'currency' => 'EUR', 'price_amount' => 900 ] );

    $money = app( ProductPriceResolver::class )->resolve( $product, 'EUR' );

    expect( $money )->not->toBeNull();
    expect( $money->getAmount() )->toBe( '900' );
    expect( $money->getCurrency()->getCode() )->toBe( 'EUR' );
} );

it( 'falls back to base-currency price converted via the active provider', function (): void {
    config()->set( 'artisanpack.ecommerce.base_currency', 'USD' );
    config()->set( 'artisanpack.ecommerce.currency.provider', 'config' );
    // 1 USD -> 0.92 CAD (in reality would be higher, but the number keeps the
    // math obvious): 10_000 minor USD * 0.92 = 9_200 minor CAD.
    config()->set( 'artisanpack.ecommerce.currency.rates', [
        'USD' => [ 'CAD' => 92_000_000 ],
    ] );

    $product = Product::factory()->simple()->create();
    ProductPrice::factory()
        ->forPriceable( $product )
        ->create( [ 'currency' => 'USD', 'price_amount' => 10_000 ] );

    $money = app( ProductPriceResolver::class )->resolve( $product, 'CAD' );

    expect( $money )->not->toBeNull();
    expect( $money->getAmount() )->toBe( '9200' );
    expect( $money->getCurrency()->getCode() )->toBe( 'CAD' );
} );

it( 'returns null when no explicit row and no base-currency row exists', function (): void {
    config()->set( 'artisanpack.ecommerce.base_currency', 'USD' );

    $product = Product::factory()->simple()->create();
    // Only a GBP row — neither the requested EUR nor the base USD exists.
    ProductPrice::factory()
        ->forPriceable( $product )
        ->create( [ 'currency' => 'GBP', 'price_amount' => 500 ] );

    expect( app( ProductPriceResolver::class )->resolve( $product, 'EUR' ) )->toBeNull();
} );

it( 'returns null when the base currency equals the requested currency and no explicit row exists', function (): void {
    // No fallback to try in that case — the resolver just admits the miss.
    config()->set( 'artisanpack.ecommerce.base_currency', 'USD' );

    $product = Product::factory()->simple()->create();

    expect( app( ProductPriceResolver::class )->resolve( $product, 'USD' ) )->toBeNull();
} );

it( 'prefers a scheduled window over the base row when converting via fallback', function (): void {
    config()->set( 'artisanpack.ecommerce.base_currency', 'USD' );
    config()->set( 'artisanpack.ecommerce.currency.rates', [
        'USD' => [ 'CAD' => 100_000_000 ], // 1:1 for clarity
    ] );

    $product = Product::factory()->simple()->create();

    ProductPrice::factory()
        ->forPriceable( $product )
        ->create( [ 'currency' => 'USD', 'price_amount' => 2000 ] );

    ProductPrice::factory()
        ->forPriceable( $product )
        ->create( [
            'currency'     => 'USD',
            'price_amount' => 1500,
            'starts_at'    => now()->subDay(),
            'ends_at'      => now()->addDay(),
        ] );

    $money = app( ProductPriceResolver::class )->resolve( $product, 'CAD' );

    expect( $money->getAmount() )->toBe( '1500' );
    expect( $money->getCurrency()->getCode() )->toBe( 'CAD' );
} );

it( 'honours a swapped-in provider registered on the registry', function (): void {
    config()->set( 'artisanpack.ecommerce.currency.provider', 'stub' );

    $stub = new class implements CurrencyRateProvider {
        public function key(): string
        {
            return 'stub';
        }

        public function getRateE8( Currency $from, Currency $to ): int
        {
            // Fixed 2x rate so the test can assert exact values.
            return 200_000_000;
        }
    };

    app( CurrencyRateProviderRegistry::class )->register( 'stub', $stub );

    $product = Product::factory()->simple()->create();
    ProductPrice::factory()
        ->forPriceable( $product )
        ->create( [ 'currency' => 'USD', 'price_amount' => 500 ] );

    $money = app( ProductPriceResolver::class )->resolve( $product, 'EUR' );

    expect( $money->getAmount() )->toBe( '1000' );
} );
