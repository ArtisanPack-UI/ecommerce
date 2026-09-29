<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Registries\ShippingRateProviderRegistry;
use ArtisanPackUI\Ecommerce\Shipping\ZoneShippingRateProvider;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Money\Currency;
use Money\Money;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->provider = app( ZoneShippingRateProvider::class );
    $this->us       = new Address( address1: '1 Main', city: 'Chicago', countryCode: 'US', regionCode: 'IL', postalCode: '60601' );
    $this->uk       = new Address( address1: '1 High St', city: 'London', countryCode: 'GB', postalCode: 'SW1A 1AA' );
} );

/**
 * Creates a zone with the given methods (key => config).
 *
 * @param  array<string, mixed>                $zone
 * @param  array<int, array<string, mixed>>    $methods
 */
function zoneWith( array $zone, array $methods ): ShippingZone
{
    $zone = ShippingZone::factory()->create( $zone );

    foreach ( $methods as $position => $method ) {
        ShippingMethod::factory()->create( array_merge( [ 'zone_id' => $zone->id, 'position' => $position ], $method ) );
    }

    return $zone;
}

it( 'quotes a US-only flat rate and international weight-based rate by zone', function (): void {
    zoneWith( [ 'name' => 'US', 'country_codes' => [ 'US' ] ], [
        [ 'key' => 'flat-rate', 'label' => 'Standard', 'config' => [ 'amount' => 500 ] ],
    ] );
    zoneWith( [ 'name' => 'International', 'country_codes' => [ 'GB', 'FR' ], 'priority' => 10 ], [
        [ 'key' => 'weight-based', 'label' => 'International', 'config' => [ 'unit' => 'kg', 'tiers' => [
            [ 'max_weight' => 1, 'amount' => 1_500 ],
            [ 'max_weight' => 5, 'amount' => 3_000 ],
        ] ] ],
    ] );

    $cart = cartWithLines( [ [ 'unit' => 2_000, 'qty' => 2, 'product' => [ 'weight' => '0.750', 'weight_unit' => 'kg' ] ] ] );

    $us = $this->provider->getRatesForCart( $cart, $this->us );
    $uk = $this->provider->getRatesForCart( $cart, $this->uk );

    expect( $us )->toHaveCount( 1 );
    expect( $us->first()->label )->toBe( 'Standard' );
    expect( (int) $us->first()->amount->getAmount() )->toBe( 500 );

    // 1.5 kg → second tier.
    expect( (int) $uk->first()->amount->getAmount() )->toBe( 3_000 );
} );

it( 'picks the first matching zone by priority and honours region + postal narrowing', function (): void {
    zoneWith( [ 'name' => 'Chicago', 'country_codes' => [ 'US' ], 'region_codes' => [ 'US-IL' ], 'postal_patterns' => [ '606*' ], 'priority' => 0 ], [
        [ 'key' => 'local-pickup', 'label' => 'Pickup', 'config' => [ 'location' => 'Studio, 1 Main St' ] ],
    ] );
    zoneWith( [ 'name' => 'Rest of US', 'country_codes' => [ 'US' ], 'priority' => 5 ], [
        [ 'key' => 'flat-rate', 'label' => 'Standard', 'config' => [ 'amount' => 800 ] ],
    ] );

    $cart = cartWithLines( [ [ 'unit' => 1_000 ] ] );

    $chicago = $this->provider->getRatesForCart( $cart, $this->us );
    $boston  = $this->provider->getRatesForCart( $cart, new Address( address1: 'x', city: 'Boston', countryCode: 'US', regionCode: 'MA', postalCode: '02108' ) );

    expect( $chicago->pluck( 'label' )->all() )->toBe( [ 'Pickup' ] );
    expect( $chicago->first()->amount->isZero() )->toBeTrue();
    expect( $chicago->first()->meta )->toBe( [ 'location' => 'Studio, 1 Main St' ] );
    expect( $boston->pluck( 'label' )->all() )->toBe( [ 'Standard' ] );
} );

it( 'adds per-item charges to flat rates', function (): void {
    zoneWith( [ 'country_codes' => [ 'US' ] ], [
        [ 'key' => 'flat-rate', 'config' => [ 'amount' => 500, 'per_item_amount' => 100 ] ],
    ] );

    $rates = $this->provider->getRatesForCart( cartWithLines( [ [ 'qty' => 3 ], [ 'qty' => 2 ] ] ), $this->us );

    expect( (int) $rates->first()->amount->getAmount() )->toBe( 1_000 );
} );

it( 'hides free shipping below its threshold', function ( int $unit, bool $offered ): void {
    zoneWith( [ 'country_codes' => [ 'US' ] ], [
        [ 'key' => 'free-shipping', 'label' => 'Free', 'config' => [ 'min_subtotal' => 7_500 ] ],
    ] );

    $rates = $this->provider->getRatesForCart( cartWithLines( [ [ 'unit' => $unit ] ] ), $this->us );

    expect( $rates->isNotEmpty() )->toBe( $offered );
} )->with( [
    'below $75' => [ 7_499, false ],
    'at $75'    => [ 7_500, true ],
] );

it( 'picks the highest price-based tier the subtotal meets', function ( int $unit, ?int $expected ): void {
    zoneWith( [ 'country_codes' => [ 'US' ] ], [
        [ 'key' => 'price-based', 'config' => [ 'tiers' => [
            [ 'min_subtotal' => 1_000, 'amount' => 900 ],
            [ 'min_subtotal' => 5_000, 'amount' => 500 ],
            [ 'min_subtotal' => 10_000, 'amount' => 0 ],
        ] ] ],
    ] );

    $rates = $this->provider->getRatesForCart( cartWithLines( [ [ 'unit' => $unit ] ] ), $this->us );

    expect( $rates->first()?->amount->getAmount() )->toBe( null === $expected ? null : (string) $expected );
} )->with( [
    'below all tiers' => [ 999, null ],
    'first tier'      => [ 4_999, 900 ],
    'middle tier'     => [ 5_000, 500 ],
    'top tier'        => [ 25_000, 0 ],
] );

it( 'converts weight units and falls through to a catch-all tier', function (): void {
    zoneWith( [ 'country_codes' => [ 'US' ] ], [
        [ 'key' => 'weight-based', 'config' => [ 'unit' => 'lb', 'tiers' => [
            [ 'max_weight' => 1, 'amount' => 400 ],
            [ 'max_weight' => null, 'amount' => 1_200 ],
        ] ] ],
    ] );

    $light = cartWithLines( [ [ 'product' => [ 'weight' => '400', 'weight_unit' => 'g' ] ] ] );
    $heavy = cartWithLines( [ [ 'qty' => 3, 'product' => [ 'weight' => '12', 'weight_unit' => 'oz' ] ] ] );

    expect( (int) $this->provider->getRatesForCart( $light, $this->us )->first()->amount->getAmount() )->toBe( 400 );
    expect( (int) $this->provider->getRatesForCart( $heavy, $this->us )->first()->amount->getAmount() )->toBe( 1_200 );
} );

it( 'hides weight-based shipping when the cart is heavier than every tier', function (): void {
    zoneWith( [ 'country_codes' => [ 'US' ] ], [
        [ 'key' => 'weight-based', 'config' => [ 'unit' => 'kg', 'tiers' => [ [ 'max_weight' => 1, 'amount' => 400 ] ] ] ],
    ] );

    $cart = cartWithLines( [ [ 'product' => [ 'weight' => '2', 'weight_unit' => 'kg' ] ] ] );

    expect( $this->provider->getRatesForCart( $cart, $this->us ) )->toBeEmpty();
} );

it( 'uses explicit per-currency amounts and converts base-currency amounts otherwise', function (): void {
    config()->set( 'artisanpack.ecommerce.currency.rates', [ 'USD' => [ 'EUR' => 90_000_000 ] ] );

    zoneWith( [ 'country_codes' => [ 'US' ] ], [
        [ 'key' => 'flat-rate', 'label' => 'Mapped', 'config' => [ 'amount' => [ 'USD' => 500, 'GBP' => 400 ] ] ],
        [ 'key' => 'flat-rate', 'label' => 'Converted', 'config' => [ 'amount' => 1_000 ] ],
    ] );

    $gbp = $this->provider->getRatesForCart( cartWithLines( [ [] ], 'GBP' ), $this->us );
    $eur = $this->provider->getRatesForCart( cartWithLines( [ [] ], 'EUR' ), $this->us );

    expect( $gbp->firstWhere( 'label', 'Mapped' )->amount->getAmount() )->toBe( '400' );
    expect( $eur->firstWhere( 'label', 'Converted' )->amount )->toEqual( new Money( 900, new Currency( 'EUR' ) ) );
} );

it( 'delegates provider:{key} methods to a registered real-time provider', function (): void {
    app( ShippingRateProviderRegistry::class )->register( 'shippo', new class implements ShippingRateProvider {
        public function key(): string
        {
            return 'shippo';
        }

        public function label(): string
        {
            return 'Shippo';
        }

        public function getRatesForCart( Cart $cart, Address $destination ): Collection
        {
            return new Collection( [
                new ShippingRate( 'usps-priority', 'USPS Priority', new Money( 845, new Currency( 'USD' ) ), carrier: 'usps', service: 'priority' ),
                new ShippingRate( 'ups-ground', 'UPS Ground', new Money( 1_020, new Currency( 'USD' ) ), carrier: 'ups', service: 'ground' ),
            ] );
        }
    } );

    zoneWith( [ 'country_codes' => [ 'US' ] ], [
        [ 'key' => 'provider:shippo', 'label' => 'Carrier rates' ],
        [ 'key' => 'provider:not-installed', 'label' => 'Missing' ],
    ] );

    $rates = $this->provider->getRatesForCart( cartWithLines( [ [] ] ), $this->us );

    expect( $rates->pluck( 'methodKey' )->all() )->toBe( [ 'shippo:usps-priority', 'shippo:ups-ground' ] );
    expect( $rates->first()->carrier )->toBe( 'usps' );
    expect( $rates->first()->shippingMethodId )->not->toBeNull();
} );

it( 'skips unknown and inactive methods, and returns nothing for empty carts or unmatched destinations', function (): void {
    zoneWith( [ 'country_codes' => [ 'US' ] ], [
        [ 'key' => 'does-not-exist', 'label' => 'Ghost' ],
        [ 'key' => 'flat-rate', 'label' => 'Off', 'is_active' => false ],
        [ 'key' => 'flat-rate', 'label' => 'On', 'config' => [ 'amount' => 100 ] ],
    ] );

    expect( $this->provider->getRatesForCart( cartWithLines( [ [] ] ), $this->us )->pluck( 'label' )->all() )->toBe( [ 'On' ] );
    expect( $this->provider->getRatesForCart( cartWithLines( [] ), $this->us ) )->toBeEmpty();
    expect( $this->provider->getRatesForCart( cartWithLines( [ [] ] ), $this->uk ) )->toBeEmpty();
} );

it( 'runs rateCalculated per method and availableMethods over the final list', function (): void {
    zoneWith( [ 'country_codes' => [ 'US' ] ], [
        [ 'key' => 'flat-rate', 'label' => 'A', 'config' => [ 'amount' => 500 ] ],
        [ 'key' => 'flat-rate', 'label' => 'B', 'config' => [ 'amount' => 700 ] ],
    ] );

    addFilter( 'ap.ecommerce.shipping.rateCalculated', fn ( Money $rate, ShippingMethod $method ) => 'A' === $method->label ? $rate->add( new Money( 50, $rate->getCurrency() ) ) : $rate );
    addFilter( 'ap.ecommerce.shipping.availableMethods', fn ( array $rates ) => array_filter( $rates, fn ( ShippingRate $r ) => 'B' !== $r->label ) );

    $rates = $this->provider->getRatesForCart( cartWithLines( [ [] ] ), $this->us );

    expect( $rates->pluck( 'label' )->all() )->toBe( [ 'A' ] );
    expect( (int) $rates->first()->amount->getAmount() )->toBe( 550 );
} );
