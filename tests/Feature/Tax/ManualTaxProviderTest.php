<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\TaxClass;
use ArtisanPackUI\Ecommerce\Models\TaxRate;
use ArtisanPackUI\Ecommerce\Tax\ManualTaxProvider;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\TaxContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->provider = app( ManualTaxProvider::class );
    $this->chicago  = new Address( address1: '1 Main', city: 'Chicago', countryCode: 'US', regionCode: 'IL', postalCode: '60601' );
} );

it( 'seeds the four default tax classes', function (): void {
    expect( TaxClass::query()->pluck( 'key' )->all() )
        ->toEqualCanonicalizing( [ 'standard', 'reduced', 'zero', 'digital' ] );
} );

it( 'levies a four-decimal rate exactly, rounding half up per line', function (): void {
    TaxRate::factory()->create( [ 'region_code' => 'IL', 'rate_ubps' => 83_750_000, 'label' => 'Cook County' ] );

    $cart   = cartWithLines( [ [ 'unit' => 10_000, 'qty' => 1 ] ] );
    $result = $this->provider->calculate( $cart, $this->chicago );

    // 10000 × 8.375% = 837.5 → 838.
    expect( (int) $result->total->getAmount() )->toBe( 838 );
    expect( $result->breakdown )->toHaveCount( 1 );
    expect( $result->breakdown[0]['rate_ubps'] )->toBe( 83_750_000 );
    expect( (int) $result->perLine[ $cart->items->first()->id ]->getAmount() )->toBe( 838 );
} );

it( 'keeps only the most specific match within a priority level', function (): void {
    TaxRate::factory()->create( [ 'rate_ubps' => 10_000_000, 'label' => 'US default' ] );
    TaxRate::factory()->create( [ 'region_code' => 'IL', 'rate_ubps' => 62_500_000, 'label' => 'Illinois' ] );
    TaxRate::factory()->create( [ 'region_code' => 'IL', 'postal_pattern' => '606*', 'rate_ubps' => 102_500_000, 'label' => 'Chicago' ] );

    $result = $this->provider->calculate( cartWithLines( [ [ 'unit' => 10_000 ] ] ), $this->chicago );

    expect( array_column( $result->breakdown, 'label' ) )->toBe( [ 'Chicago' ] );
    expect( (int) $result->total->getAmount() )->toBe( 1_025 );
} );

it( 'stacks rates from different priority levels', function (): void {
    TaxRate::factory()->create( [ 'region_code' => 'IL', 'rate_ubps' => 62_500_000, 'priority' => 1, 'label' => 'State' ] );
    TaxRate::factory()->create( [ 'region_code' => 'IL', 'rate_ubps' => 17_500_000, 'priority' => 2, 'label' => 'County' ] );

    $result = $this->provider->calculate( cartWithLines( [ [ 'unit' => 10_000 ] ] ), $this->chicago );

    expect( array_column( $result->breakdown, 'label' ) )->toBe( [ 'State', 'County' ] );
    expect( (int) $result->total->getAmount() )->toBe( 800 );
} );

it( 'matches postal ranges and ignores rates for other regions', function (): void {
    TaxRate::factory()->create( [ 'postal_pattern' => '60600...60699', 'rate_ubps' => 50_000_000, 'label' => 'Range' ] );
    TaxRate::factory()->create( [ 'region_code' => 'CA', 'rate_ubps' => 72_500_000, 'label' => 'California' ] );
    TaxRate::factory()->inactive()->create( [ 'rate_ubps' => 99_000_000, 'priority' => 5, 'label' => 'Inactive' ] );

    $result = $this->provider->calculate( cartWithLines( [ [ 'unit' => 2_000 ] ] ), $this->chicago );

    expect( array_column( $result->breakdown, 'label' ) )->toBe( [ 'Range' ] );
    expect( (int) $result->total->getAmount() )->toBe( 100 );
} );

it( 'applies compound rates on base plus prior taxes (Canadian GST + PST style)', function (): void {
    $address = new Address( address1: '1 Rue', city: 'Charlottetown', countryCode: 'CA', regionCode: 'PE' );

    TaxRate::factory()->create( [ 'country_code' => 'CA', 'rate_ubps' => 50_000_000, 'priority' => 1, 'label' => 'GST' ] );
    TaxRate::factory()->compound()->create( [ 'country_code' => 'CA', 'region_code' => 'PE', 'rate_ubps' => 100_000_000, 'priority' => 2, 'label' => 'PST' ] );

    $result = $this->provider->calculate( cartWithLines( [ [ 'unit' => 10_000 ] ], 'CAD' ), $address );

    // GST 500 on 10000; PST 10% on 10500 = 1050.
    expect( array_map( fn ( $row ) => (int) $row['amount']->getAmount(), $result->breakdown ) )->toBe( [ 500, 1_050 ] );
    expect( (int) $result->total->getAmount() )->toBe( 1_550 );
    expect( $result->total->getCurrency()->getCode() )->toBe( 'CAD' );
} );

it( 'back-calculates VAT from tax-inclusive prices', function (): void {
    config()->set( 'artisanpack.ecommerce.tax.prices_include_tax', true );

    $berlin = new Address( address1: 'Str. 1', city: 'Berlin', countryCode: 'DE' );
    TaxRate::factory()->create( [ 'country_code' => 'DE', 'rate_ubps' => 190_000_000, 'label' => 'MwSt' ] );

    $result = $this->provider->calculate( cartWithLines( [ [ 'unit' => 11_900 ] ], 'EUR' ), $berlin );

    expect( $result->pricesIncludeTax )->toBeTrue();
    expect( (int) $result->total->getAmount() )->toBe( 1_900 );
} );

it( 'keeps inclusive breakdowns summing exactly to the back-calculated total', function (): void {
    config()->set( 'artisanpack.ecommerce.tax.prices_include_tax', true );

    $address = new Address( address1: '1 Rue', city: 'Montréal', countryCode: 'CA', regionCode: 'QC' );
    TaxRate::factory()->create( [ 'country_code' => 'CA', 'rate_ubps' => 50_000_000, 'priority' => 1, 'label' => 'GST' ] );
    TaxRate::factory()->compound()->create( [ 'country_code' => 'CA', 'region_code' => 'QC', 'rate_ubps' => 99_750_000, 'priority' => 2, 'label' => 'QST' ] );

    $cart   = cartWithLines( [ [ 'unit' => 999, 'qty' => 3 ], [ 'unit' => 1_234, 'qty' => 1 ] ], 'CAD' );
    $result = $this->provider->calculate( $cart, $address );

    $breakdownSum = array_sum( array_map( fn ( $row ) => (int) $row['amount']->getAmount(), $result->breakdown ) );
    $perLineSum   = array_sum( array_map( fn ( $m ) => (int) $m->getAmount(), $result->perLine ) );

    expect( $breakdownSum )->toBe( (int) $result->total->getAmount() );
    expect( $perLineSum )->toBe( (int) $result->total->getAmount() );
} );

it( 'skips non-taxable products and honours per-product tax classes', function (): void {
    TaxRate::factory()->create( [ 'rate_ubps' => 100_000_000, 'label' => 'Standard' ] );
    TaxRate::factory()->create( [ 'tax_class_key' => 'reduced', 'rate_ubps' => 50_000_000, 'label' => 'Reduced' ] );

    $cart = cartWithLines( [
        [ 'unit' => 1_000, 'product' => [ 'is_taxable' => false ] ],
        [ 'unit' => 1_000, 'product' => [ 'tax_class_key' => 'reduced' ] ],
        [ 'unit' => 1_000, 'product' => [ 'tax_class_key' => 'zero' ] ],
        [ 'unit' => 1_000 ],
    ] );

    $result = $this->provider->calculate( $cart, $this->chicago );
    $lines  = array_values( array_map( fn ( $m ) => (int) $m->getAmount(), $result->perLine ) );

    expect( $lines )->toBe( [ 0, 50, 0, 100 ] );
    expect( (int) $result->total->getAmount() )->toBe( 150 );
} );

it( 'taxes shipping only through rates flagged is_shipping_taxable', function (): void {
    TaxRate::factory()->create( [ 'rate_ubps' => 100_000_000, 'is_shipping_taxable' => true, 'priority' => 1, 'label' => 'State' ] );
    TaxRate::factory()->create( [ 'rate_ubps' => 20_000_000, 'priority' => 2, 'label' => 'Local' ] );

    $cart = cartWithLines( [ [ 'unit' => 1_000 ] ], 'USD', [ 'shipping_amount' => 500 ] );

    $result = $this->provider->calculate( $cart, $this->chicago );

    expect( (int) $result->shipping->getAmount() )->toBe( 50 );
    expect( (int) $result->total->getAmount() )->toBe( 100 + 20 + 50 );
} );

it( 'spreads the cart-level discount across lines before taxing', function (): void {
    TaxRate::factory()->create( [ 'rate_ubps' => 100_000_000 ] );

    $cart = cartWithLines( [ [ 'unit' => 3_000 ], [ 'unit' => 1_000 ] ], 'USD', [ 'discount_amount' => 1_000 ] );

    $result = $this->provider->calculate( $cart, $this->chicago );

    expect( array_values( array_map( fn ( $m ) => (int) $m->getAmount(), $result->perLine ) ) )->toBe( [ 225, 75 ] );
} );

it( 'returns zero tax with zero-filled lines when nothing matches', function (): void {
    $cart   = cartWithLines( [ [ 'unit' => 1_000 ], [ 'unit' => 2_000 ] ] );
    $result = $this->provider->calculate( $cart, new Address( address1: 'x', city: 'Paris', countryCode: 'FR' ) );

    expect( $result->total->isZero() )->toBeTrue();
    expect( $result->perLine )->toHaveCount( 2 );
    expect( $result->breakdown )->toBe( [] );
} );

it( 'lets the ap.ecommerce.tax.rates filter rewrite the resolved rates', function (): void {
    TaxRate::factory()->create( [ 'rate_ubps' => 100_000_000 ] );

    addFilter( 'ap.ecommerce.tax.rates', function ( array $rates, TaxContext $context ): array {
        expect( $context->taxClassKey )->toBe( 'standard' );

        return [];
    } );

    $result = $this->provider->calculate( cartWithLines( [ [ 'unit' => 1_000 ] ] ), $this->chicago );

    expect( $result->total->isZero() )->toBeTrue();
} );

it( 'matches postal codes with ZIP+4, ranges by leading digits, and leading zeros (D11)', function ( string $patterns, string $code, bool $matches ): void {
    expect( TaxRate::postalMatches( $patterns, $code ) )->toBe( $matches );
} )->with( [
    'zip+4 in range'           => [ '60601...60699', '60614-1234', true ],
    'zip+4 exact base'         => [ '60614', '60614-1234', true ],
    'zip+4 glob'               => [ '606*', '60614-1234', true ],
    'leading zeros out'        => [ '100...199', '00150', false ],
    'leading digits in'        => [ '100...199', '15000', true ],
    'mismatched bounds'        => [ '100...1999', '150', false ],
    'spaces ignored'           => [ 'SW1A1AA', 'sw1a 1aa', true ],
    'outside range'            => [ '60601...60699', '60700', false ],
    'short code against range' => [ '60601...60699', '606', false ],
] );
