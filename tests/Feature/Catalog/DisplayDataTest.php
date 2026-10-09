<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Inventory\StockLevels;
use ArtisanPackUI\Ecommerce\Inventory\StockStatus;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\TaxRate;
use ArtisanPackUI\Ecommerce\Pricing\PriceDisplayResolver;
use ArtisanPackUI\Ecommerce\Tax\TaxRateCandidates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.store.country', 'US' );
} );

/**
 * A simple product priced at `$amount` USD with `$onHand` units tracked.
 */
function ddProduct( int $amount, ?int $onHand = 5, array $prices = [] ): Product
{
    $product = Product::factory()->create();
    ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => $amount, 'compare_at_amount' => null ] );

    foreach ( $prices as $row ) {
        ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'compare_at_amount' => null ] + $row );
    }

    if ( null !== $onHand ) {
        InventoryItem::factory()->create( [
            'stockable_type'    => $product->getMorphClass(),
            'stockable_id'      => $product->id,
            'track_inventory'   => true,
            'allow_backorder'   => false,
            'quantity_on_hand'  => $onHand,
            'quantity_reserved' => 0,
        ] );
    }

    return $product;
}

/**
 * The SQL run by `$callback`.
 *
 * @return array<int, string>
 */
function ddQueries( callable $callback ): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $queries = array_column( DB::getQueryLog(), 'query' );
    DB::disableQueryLog();

    return $queries;
}

it( 'prices and stocks a listing without a query per product once its display data is loaded', function (): void {
    TaxRate::factory()->create( [ 'tax_class_key' => 'standard', 'country_code' => 'US' ] );

    $count = static function (): int {
        $products = Product::query()->withDisplayData()->get();

        return count( ddQueries( static function () use ( $products ): void {
            foreach ( $products as $product ) {
                app( PriceDisplayResolver::class )->for( $product, 'USD' );
                StockStatus::for( $product );
            }
        } ) );
    };

    ddProduct( 1000 );
    ddProduct( 2000 );
    app()->forgetScopedInstances();
    $few = $count();

    foreach ( range( 1, 6 ) as $i ) {
        ddProduct( 1000 + $i );
    }

    app()->forgetScopedInstances();

    expect( $count() )->toBe( $few );
} );

it( 'gives the same price from loaded rows as from the database, scheduled sales included', function (): void {
    $product = ddProduct( 3000, null, [
        [ 'price_amount' => 2500, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay() ],
        [ 'price_amount' => 2000, 'starts_at' => now()->addWeek(), 'ends_at' => null ],
    ] );

    $queried = app( PriceDisplayResolver::class )->for( $product->fresh(), 'USD' );
    $loaded  = Product::query()->withDisplayData()->find( $product->id );

    $queries = ddQueries( static function () use ( $loaded, &$display ): void {
        $display = app( PriceDisplayResolver::class )->for( $loaded, 'USD' );
    } );

    expect( $display?->price->getAmount() )->toBe( '2500' )
        ->and( $display?->price->equals( $queried->price ) )->toBeTrue()
        ->and( array_filter( $queries, static fn ( string $sql ): bool => str_contains( $sql, 'ecommerce_product_prices' ) ) )->toBe( [] );
} );

it( 'prices a variable product\'s range from its loaded variants', function (): void {
    $product = Product::factory()->create( [ 'type' => 'variable' ] );

    foreach ( [ 1500, 900, 1200 ] as $position => $amount ) {
        $variant = ProductVariant::factory()->for( $product )->create( [ 'position' => $position ] );
        ProductPrice::factory()->forPriceable( $variant )->create( [ 'currency' => 'USD', 'price_amount' => $amount, 'compare_at_amount' => null ] );
    }

    $loaded = Product::query()->withDisplayData()->find( $product->id );

    $queries = ddQueries( static function () use ( $loaded, &$display ): void {
        $display = app( PriceDisplayResolver::class )->for( $loaded, 'USD' );
    } );

    expect( $display?->minPrice?->getAmount() )->toBe( '900' )
        ->and( $display?->maxPrice?->getAmount() )->toBe( '1500' )
        ->and( array_filter( $queries, static fn ( string $sql ): bool => str_contains( $sql, 'ecommerce_product_variants' ) || str_contains( $sql, 'ecommerce_product_prices' ) ) )->toBe( [] );
} );

it( 'reads stock from loaded rows only when display code opts in', function (): void {
    $product = ddProduct( 1000, 2 );
    $loaded  = Product::query()->withDisplayData()->find( $product->id );

    InventoryItem::query()->where( 'stockable_id', $product->id )->update( [ 'quantity_on_hand' => 0 ] );

    $levels = app( StockLevels::class );

    expect( $levels->available( $loaded ) )->toBe( 0 )
        ->and( $levels->available( $loaded, null, true ) )->toBe( 2 )
        ->and( StockStatus::for( $loaded )->status )->toBe( StockStatus::IN_STOCK )
        ->and( StockStatus::for( $product->fresh() )->status )->toBe( StockStatus::OUT_OF_STOCK );
} );

it( 'rejects a variant of another product when the variants are loaded', function (): void {
    $product = ddProduct( 1000 );
    $other   = ProductVariant::factory()->create();
    $loaded  = Product::query()->withDisplayData()->find( $product->id );

    $loaded->productType()->priceLine( $loaded, [ 'variant_id' => $other->id ], 1, 'USD' );
} )->throws( RuntimeException::class, 'does not belong' );

it( 'reads the tax rates once per request and again after a rate changes', function (): void {
    $rate = TaxRate::factory()->create( [ 'tax_class_key' => 'standard', 'country_code' => 'US' ] );
    $a    = ddProduct( 1000, null );
    $b    = ddProduct( 2000, null );

    $queries = ddQueries( static function () use ( $a, $b ): void {
        app( PriceDisplayResolver::class )->for( $a, 'USD' );
        app( PriceDisplayResolver::class )->for( $b, 'USD' );
    } );

    expect( array_filter( $queries, static fn ( string $sql ): bool => str_contains( $sql, 'ecommerce_tax_rates' ) ) )->toHaveCount( 1 );

    $before = app( PriceDisplayResolver::class )->for( $a, 'USD' );
    $rate->update( [ 'rate_ubps' => (int) $rate->rate_ubps * 2 ] );
    $after = app( PriceDisplayResolver::class )->for( $a, 'USD' );

    expect( $after?->priceIncludingTax->greaterThan( $before->priceIncludingTax ) )->toBeTrue();
} );

it( 'starts each request with no tax rates read', function (): void {
    TaxRate::factory()->create( [ 'tax_class_key' => 'standard', 'country_code' => 'US' ] );

    $first = app( TaxRateCandidates::class )->for( 'standard', 'us' );

    DB::table( 'ecommerce_tax_rates' )->delete();
    app()->forgetScopedInstances();

    expect( $first )->toHaveCount( 1 )
        ->and( app( TaxRateCandidates::class )->for( 'standard', 'US' ) )->toHaveCount( 0 );
} );
