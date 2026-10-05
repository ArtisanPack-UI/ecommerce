<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\ValueObjects\Address;

it( 'hydrates from and serialises to the orders.shipping_address JSON shape', function (): void {
    $data = [
        'first_name'   => 'Ada',
        'last_name'    => 'Lovelace',
        'company'      => null,
        'phone'        => '+1-555-0100',
        'address1'     => '10 Analytical Engine Ln',
        'address2'     => 'Unit 1',
        'city'         => 'Berkeley',
        'region'       => 'California',
        'region_code'  => 'CA',
        'postal_code'  => '94704',
        'country_code' => 'US',
    ];

    $address = Address::fromArray( $data );

    expect( $address->address1 )->toBe( '10 Analytical Engine Ln' );
    expect( $address->countryCode )->toBe( 'US' );
    expect( $address->regionCode )->toBe( 'CA' );
    expect( $address->toArray() )->toBe( $data );
} );

it( 'uppercases the country code from array input', function (): void {
    $address = Address::fromArray( [
        'address1'     => '1 Foo',
        'city'         => 'Bar',
        'country_code' => 'de',
    ] );

    expect( $address->countryCode )->toBe( 'DE' );
} );

it( 'rejects a non-ISO country code', function (): void {
    new Address( address1: '1 X', city: 'Y', countryCode: 'USA' );
} )->throws( InvalidArgumentException::class, 'ISO 3166-1' );
