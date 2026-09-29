<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\TaxRate;
use ArtisanPackUI\Ecommerce\Services\TaxService;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\TaxContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'matches ISO 3166-2 region codes against plain region rates, and vice versa', function (): void {
    TaxRate::factory()->create( [ 'region_code' => 'IL', 'rate_ubps' => 62_500_000 ] );
    TaxRate::factory()->create( [ 'region_code' => 'US-NY', 'rate_ubps' => 88_750_000, 'label' => 'NY' ] );

    $service = app( TaxService::class );
    $cart    = cartWithLines( [ [ 'unit' => 10_000 ] ] );

    expect( (int) $service->calculate( $cart, new Address( '1 Main', 'Chicago', 'US', regionCode: 'US-IL' ) )->total->getAmount() )->toBe( 625 );
    expect( (int) $service->calculate( $cart, new Address( '1 Main', 'New York', 'US', regionCode: 'ny' ) )->total->getAmount() )->toBe( 888 );
} );

it( 'honours pricesIncludeTax switched by the calculating filter', function (): void {
    TaxRate::factory()->create( [ 'country_code' => 'DE', 'rate_ubps' => 190_000_000 ] );
    config()->set( 'artisanpack.ecommerce.tax.prices_include_tax', true );

    addFilter( 'ap.ecommerce.tax.calculating', fn ( TaxContext $c ) => new TaxContext( $c->destination, $c->providerKey, false ) );

    $result = app( TaxService::class )->calculate( cartWithLines( [ [ 'unit' => 10_000 ] ], 'EUR' ), new Address( 'Str. 1', 'Berlin', 'DE' ) );

    expect( $result->pricesIncludeTax )->toBeFalse();
    expect( (int) $result->total->getAmount() )->toBe( 1_900 );
} );
