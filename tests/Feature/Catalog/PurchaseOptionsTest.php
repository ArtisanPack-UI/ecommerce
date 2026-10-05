<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Catalog\VariantResolver;
use ArtisanPackUI\Ecommerce\Inventory\StockStatus;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductAttribute;
use ArtisanPackUI\Ecommerce\Models\ProductAttributeValue;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\ProductVariantOptionValue;
use ArtisanPackUI\Ecommerce\Models\TaxRate;
use ArtisanPackUI\Ecommerce\Pricing\PriceDisplayResolver;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Money\Money;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.store.country', 'US' );
} );

/**
 * A simple product priced at `$amount` USD (and `$compareAt`).
 */
function poProduct( int $amount, ?int $compareAt = null ): Product
{
    $product = Product::factory()->create();
    ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => $amount, 'compare_at_amount' => $compareAt ] );

    return $product;
}

/**
 * A variable product with sizes; `$sizes` maps a size to [ price, compare_at, on_hand|null ].
 *
 * @param  array<string, array{0: int, 1: int|null, 2: int|null}>  $sizes
 * @param  string                                                   $prefix  SKU prefix.
 *
 * @return array{0: Product, 1: array<string, ProductVariant>, 2: array<string, ProductAttributeValue>}
 */
function poVariable( array $sizes, string $prefix = 'SHIRT' ): array
{
    $product   = Product::factory()->create( [ 'type' => 'variable' ] );
    $attribute = ProductAttribute::factory()->for( $product )->create( [ 'key' => 'size', 'label' => 'Size' ] );
    $variants  = [];
    $values    = [];

    foreach ( $sizes as $size => [ $price, $compareAt, $onHand ] ) {
        $values[ $size ]   = ProductAttributeValue::factory()->for( $attribute, 'attribute' )->create( [ 'value' => $size, 'label' => strtoupper( $size ) ] );
        $variants[ $size ] = ProductVariant::factory()->for( $product )->create( [ 'sku' => $prefix . '-' . strtoupper( $size ) ] );

        ProductVariantOptionValue::create( [ 'product_variant_id' => $variants[ $size ]->id, 'product_attribute_id' => $attribute->id, 'product_attribute_value_id' => $values[ $size ]->id ] );
        ProductPrice::factory()->forPriceable( $variants[ $size ] )->create( [ 'currency' => 'USD', 'price_amount' => $price, 'compare_at_amount' => $compareAt ] );

        if ( null !== $onHand ) {
            InventoryItem::factory()->create( [ 'stockable_type' => $variants[ $size ]->getMorphClass(), 'stockable_id' => $variants[ $size ]->id, 'quantity_on_hand' => $onHand, 'quantity_reserved' => 0 ] );
        }
    }

    return [ $product, $variants, $values ];
}

function displayPrices(): PriceDisplayResolver
{
    return app( PriceDisplayResolver::class );
}

it( 'shows the price, the compare-at price, and the price with tax for a tax-exclusive store', function (): void {
    TaxRate::factory()->create( [ 'rate_ubps' => 200_000_000, 'label' => 'Sales tax' ] );

    $price = displayPrices()->for( poProduct( 2_500, 3_000 ), 'USD' );

    expect( $price->price->getAmount() )->toBe( '2500' )
        ->and( $price->compareAt->getAmount() )->toBe( '3000' )
        ->and( $price->onSale() )->toBeTrue()
        ->and( $price->priceExcludingTax->getAmount() )->toBe( '2500' )
        ->and( $price->priceIncludingTax->getAmount() )->toBe( '3000' )
        ->and( $price->pricesIncludeTax )->toBeFalse()
        ->and( $price->taxLabel )->toBe( 'Sales tax' );
} );

it( 'backs tax out of a tax-inclusive store\'s price, at the shopper\'s destination', function (): void {
    config()->set( 'artisanpack.ecommerce.tax.prices_include_tax', true );
    TaxRate::factory()->create( [ 'country_code' => 'GB', 'rate_ubps' => 200_000_000, 'label' => 'VAT' ] );

    $product = poProduct( 1_200 );
    $uk      = displayPrices()->for( $product, 'USD', Address::fromArray( [ 'country_code' => 'GB' ] ) );
    $us      = displayPrices()->for( $product, 'USD' );

    expect( $uk->priceIncludingTax->getAmount() )->toBe( '1200' )
        ->and( $uk->priceExcludingTax->getAmount() )->toBe( '1000' )
        ->and( $uk->taxLabel )->toBe( 'VAT' )
        ->and( $us->priceExcludingTax->getAmount() )->toBe( '1200' )
        ->and( $us->taxLabel )->toBeNull();
} );

it( 'uses a scheduled sale price while it runs and ignores a compare-at below the price', function (): void {
    Carbon::setTestNow( '2026-10-05 12:00:00' );
    $product = poProduct( 2_000, 1_500 );
    ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => 1_600, 'compare_at_amount' => 2_000, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-10' ] );

    $during = displayPrices()->for( $product, 'USD' );

    Carbon::setTestNow( '2026-10-11 12:00:00' );
    $after = displayPrices()->for( $product, 'USD' );
    Carbon::setTestNow();

    expect( $during->price->getAmount() )->toBe( '1600' )
        ->and( $during->onSale() )->toBeTrue()
        ->and( $after->price->getAmount() )->toBe( '2000' )
        ->and( $after->onSale() )->toBeFalse();
} );

it( 'shows a variable product from its cheapest variant with the range', function (): void {
    [ $product ] = poVariable( [ 's' => [ 1_900, 2_500, null ], 'l' => [ 2_500, null, null ] ] );

    $price = displayPrices()->for( $product, 'USD' );

    expect( $price->isRange() )->toBeTrue()
        ->and( $price->minPrice->getAmount() )->toBe( '1900' )
        ->and( $price->maxPrice->getAmount() )->toBe( '2500' )
        ->and( $price->price->getAmount() )->toBe( '1900' )
        ->and( $price->compareAt->getAmount() )->toBe( '2500' )
        ->and( $price->toArray()['on_sale'] )->toBeTrue();
} );

it( 'runs display prices through ap.ecommerce.pricing.priceDisplay', function (): void {
    $product = poProduct( 2_000 );
    addFilter( 'ap.ecommerce.pricing.priceDisplay', fn ( Money $price, $subject, $customer ): Money => $subject->is( $product ) ? $price->subtract( Money::USD( 500 ) ) : $price );

    expect( displayPrices()->for( $product, 'USD' )->price->getAmount() )->toBe( '1500' );

    removeAllFilters( 'ap.ecommerce.pricing.priceDisplay' );
} );

it( 'has no display price without a price in the currency', function (): void {
    config()->set( 'artisanpack.ecommerce.base_currency', 'USD' );

    expect( displayPrices()->for( Product::factory()->create(), 'USD' ) )->toBeNull();
} );

it( 'matches attribute choices to a variant and greys out combinations that are sold out', function (): void {
    [ $product, $variants, $values ] = poVariable( [ 's' => [ 1_900, null, 5 ], 'xl' => [ 2_100, null, 0 ] ] );

    $resolver = app( VariantResolver::class );

    expect( $resolver->match( $product, [ $values['xl']->id ] )?->is( $variants['xl'] ) )->toBeTrue()
        ->and( $resolver->match( $product, [ 999_999 ] ) )->toBeNull()
        ->and( $resolver->match( $product, [] ) )->toBeNull();

    $matrix = collect( $resolver->matrix( $product, 'USD' ) )->keyBy( 'sku' );

    expect( $matrix['SHIRT-S']['available'] )->toBeTrue()
        ->and( $matrix['SHIRT-S']['attribute_value_ids'] )->toBe( [ $values['s']->id ] )
        ->and( $matrix['SHIRT-S']['price']['price']['amount'] )->toBe( 1_900 )
        ->and( $matrix['SHIRT-XL']['available'] )->toBeFalse()
        ->and( $matrix['SHIRT-XL']['stock']['status'] )->toBe( StockStatus::OUT_OF_STOCK );
} );

it( 'reports stock as in stock, low, backorder, or out without writing', function (): void {
    $untracked = Product::factory()->create();
    $low       = Product::factory()->create();
    $backorder = Product::factory()->create();
    $out       = Product::factory()->create();

    InventoryItem::factory()->create( [ 'stockable_type' => $low->getMorphClass(), 'stockable_id' => $low->id, 'quantity_on_hand' => 2, 'quantity_reserved' => 0, 'low_stock_threshold' => 3 ] );
    InventoryItem::factory()->create( [ 'stockable_type' => $backorder->getMorphClass(), 'stockable_id' => $backorder->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0, 'allow_backorder' => true ] );
    InventoryItem::factory()->create( [ 'stockable_type' => $out->getMorphClass(), 'stockable_id' => $out->id, 'quantity_on_hand' => 1, 'quantity_reserved' => 1 ] );

    $rows = InventoryItem::query()->count();

    expect( StockStatus::for( $untracked )->status )->toBe( StockStatus::IN_STOCK )
        ->and( StockStatus::for( $low )->status )->toBe( StockStatus::LOW_STOCK )
        ->and( StockStatus::for( $low )->quantity )->toBeNull()
        ->and( StockStatus::for( $backorder )->status )->toBe( StockStatus::BACKORDER )
        ->and( StockStatus::for( $out )->status )->toBe( StockStatus::OUT_OF_STOCK )
        ->and( StockStatus::for( $out )->purchasable() )->toBeFalse()
        ->and( InventoryItem::query()->count() )->toBe( $rows );

    config()->set( 'artisanpack.ecommerce.inventory.show_quantity', true );

    expect( StockStatus::for( $low )->quantity )->toBe( 2 );
} );

it( 'aggregates variant stock for a variable product', function (): void {
    config()->set( 'artisanpack.ecommerce.inventory.show_quantity', true );
    [ $product ] = poVariable( [ 's' => [ 1_900, null, 4 ], 'm' => [ 1_900, null, 0 ] ] );
    [ $soldOut ] = poVariable( [ 's' => [ 1_900, null, 0 ] ], 'TEE' );

    expect( StockStatus::for( $product )->toArray() )->toBe( [ 'status' => StockStatus::IN_STOCK, 'purchasable' => true, 'quantity' => 4 ] )
        ->and( StockStatus::for( $soldOut )->status )->toBe( StockStatus::OUT_OF_STOCK );
} );

it( 'serves price, stock, and variant options over REST', function (): void {
    TaxRate::factory()->create( [ 'country_code' => 'GB', 'rate_ubps' => 200_000_000, 'label' => 'VAT' ] );
    [ $product, , $values ] = poVariable( [ 's' => [ 1_900, 2_500, 3 ], 'xl' => [ 2_100, null, 0 ] ] );

    $this->getJson( "/api/ecommerce/v1/products/{$product->id}/purchase-options?currency=usd&country_code=GB" )
        ->assertOk()
        ->assertJsonPath( 'data.currency', 'USD' )
        ->assertJsonPath( 'data.price.min_price.amount', 1_900 )
        ->assertJsonPath( 'data.price.price_including_tax.amount', 2_280 )
        ->assertJsonPath( 'data.price.tax_label', 'VAT' )
        ->assertJsonPath( 'data.stock.status', StockStatus::IN_STOCK )
        ->assertJsonPath( 'data.variants.0.attribute_value_ids', [ $values['s']->id ] )
        ->assertJsonPath( 'data.variants.1.available', false );

    $this->getJson( "/api/ecommerce/v1/products/{$product->id}/purchase-options?currency=dollars" )->assertStatus( 422 );
    $this->getJson( '/api/ecommerce/v1/products/999999/purchase-options' )->assertNotFound();
} );
