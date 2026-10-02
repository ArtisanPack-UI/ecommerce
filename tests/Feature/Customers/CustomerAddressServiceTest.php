<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\CustomerWriteException;
use ArtisanPackUI\Ecommerce\Models\ActivityLogEntry;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerAddress;
use ArtisanPackUI\Ecommerce\Services\CustomerAddressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->addresses = app( CustomerAddressService::class );
    $this->customer  = Customer::factory()->create();
    $this->valid     = [ 'address1' => '1 Main St', 'city' => 'Springfield', 'country_code' => 'us' ];
} );

afterEach( function (): void {
    removeAllActions( 'ap.ecommerce.customer.addressAdded' );
    removeAllActions( 'ap.ecommerce.customer.addressUpdated' );
    removeAllActions( 'ap.ecommerce.customer.addressDeleted' );
} );

it( 'makes the first address the default shipping and billing address', function (): void {
    $fired = null;
    addAction( 'ap.ecommerce.customer.addressAdded', function ( Customer $customer, CustomerAddress $address ) use ( &$fired ): void {
        $fired = [ $customer->id, $address->id ];
    }, 10, 2 );

    $address = $this->addresses->create( $this->customer, $this->valid + [ 'label' => '  Home  ', 'company' => '' ], 5 );
    $entry   = ActivityLogEntry::query()->forSubject( $this->customer )->where( 'event_type', 'address.added' )->first();

    expect( $address->is_default_shipping )->toBeTrue()
        ->and( $address->is_default_billing )->toBeTrue()
        ->and( $address->country_code )->toBe( 'US' )
        ->and( $address->label )->toBe( 'Home' )
        ->and( $address->company )->toBeNull()
        ->and( $entry->actor_user_id )->toBe( 5 )
        ->and( $entry->payload )->toBe( [ 'address_id' => $address->id, 'is_default_shipping' => true, 'is_default_billing' => true ] )
        ->and( $fired )->toBe( [ $this->customer->id, $address->id ] );
} );

it( 'does not make later addresses default unless asked', function (): void {
    $first  = $this->addresses->create( $this->customer, $this->valid );
    $second = $this->addresses->create( $this->customer, $this->valid );

    expect( $second->is_default_shipping )->toBeFalse()
        ->and( $second->is_default_billing )->toBeFalse()
        ->and( $first->fresh()->is_default_shipping )->toBeTrue();
} );

it( 'moves a default flag to the address that claims it', function (): void {
    $first  = $this->addresses->create( $this->customer, $this->valid );
    $second = $this->addresses->create( $this->customer, $this->valid + [ 'is_default_billing' => true ] );

    expect( $first->fresh()->is_default_billing )->toBeFalse()
        ->and( $first->fresh()->is_default_shipping )->toBeTrue()
        ->and( $second->is_default_billing )->toBeTrue();

    $this->addresses->update( $first, [ 'is_default_billing' => true ] );

    expect( $first->fresh()->is_default_billing )->toBeTrue()
        ->and( $second->fresh()->is_default_billing )->toBeFalse();
} );

it( 'leaves other customers\' defaults alone', function (): void {
    $other      = Customer::factory()->create();
    $theirs     = $this->addresses->create( $other, $this->valid );
    $mine       = $this->addresses->create( $this->customer, $this->valid );

    expect( $theirs->fresh()->is_default_shipping )->toBeTrue()
        ->and( $mine->is_default_shipping )->toBeTrue();
} );

it( 'updates only the given fields, ignores customer_id, and records the changed fields', function (): void {
    $other   = Customer::factory()->create();
    $address = $this->addresses->create( $this->customer, $this->valid + [ 'postal_code' => '12345' ] );

    $fired = null;
    addAction( 'ap.ecommerce.customer.addressUpdated', function ( Customer $customer, CustomerAddress $address, array $changed ) use ( &$fired ): void {
        $fired = $changed;
    }, 10, 3 );

    $updated = $this->addresses->update( $address, [ 'city' => 'Shelbyville', 'customer_id' => $other->id, 'unknown' => 'x' ], 2 );
    $entry   = ActivityLogEntry::query()->forSubject( $this->customer )->where( 'event_type', 'address.updated' )->first();

    expect( $updated->city )->toBe( 'Shelbyville' )
        ->and( $updated->postal_code )->toBe( '12345' )
        ->and( $updated->customer_id )->toBe( $this->customer->id )
        ->and( $entry->payload )->toBe( [ 'address_id' => $address->id, 'fields' => [ 'city' ] ] )
        ->and( $fired )->toBe( [ 'city' ] );
} );

it( 'records no update entry when nothing changed', function (): void {
    $address = $this->addresses->create( $this->customer, $this->valid );

    $this->addresses->update( $address, [ 'city' => 'Springfield' ] );

    expect( ActivityLogEntry::query()->forSubject( $this->customer )->where( 'event_type', 'address.updated' )->count() )->toBe( 0 );
} );

it( 'rejects a missing street, city, or country on create', function (): void {
    try {
        $this->addresses->create( $this->customer, [ 'label' => 'Home' ] );
        $this->fail( 'Expected a CustomerWriteException.' );
    } catch ( CustomerWriteException $exception ) {
        expect( array_column( $exception->errors, 'field' ) )->toBe( [ 'address1', 'city', 'country_code' ] )
            ->and( array_unique( array_column( $exception->errors, 'code' ) ) )->toBe( [ 'required' ] );
    }

    expect( CustomerAddress::query()->count() )->toBe( 0 );
} );

it( 'rejects invalid values', function ( array $attributes, string $field, string $code ): void {
    try {
        $this->addresses->create( $this->customer, $attributes + $this->valid );
        $this->fail( 'Expected a CustomerWriteException.' );
    } catch ( CustomerWriteException $exception ) {
        expect( $exception->errors[0]['field'] )->toBe( $field )
            ->and( $exception->errors[0]['code'] )->toBe( $code );
    }
} )->with( [
    'three-letter country' => [ [ 'country_code' => 'USA' ], 'country_code', 'too-long' ],
    'numeric country'      => [ [ 'country_code' => '12' ], 'country_code', 'invalid-country' ],
    'long city'            => [ [ 'city' => str_repeat( 'a', 121 ) ], 'city', 'too-long' ],
    'array value'          => [ [ 'label' => [ 'x' ] ], 'label', 'invalid' ],
] );

it( 'rejects an update that blanks a required field', function (): void {
    $address = $this->addresses->create( $this->customer, $this->valid );

    expect( fn () => $this->addresses->update( $address, [ 'city' => '  ' ] ) )->toThrow( CustomerWriteException::class )
        ->and( $address->fresh()->city )->toBe( 'Springfield' );
} );

it( 'deletes an address without promoting another default', function (): void {
    $first  = $this->addresses->create( $this->customer, $this->valid );
    $second = $this->addresses->create( $this->customer, $this->valid );

    $fired = false;
    addAction( 'ap.ecommerce.customer.addressDeleted', function () use ( &$fired ): void {
        $fired = true;
    } );

    $this->addresses->delete( $first, 4 );

    $entry = ActivityLogEntry::query()->forSubject( $this->customer )->where( 'event_type', 'address.deleted' )->first();

    expect( CustomerAddress::query()->find( $first->id ) )->toBeNull()
        ->and( $second->fresh()->is_default_shipping )->toBeFalse()
        ->and( $second->fresh()->is_default_billing )->toBeFalse()
        ->and( $entry->actor_user_id )->toBe( 4 )
        ->and( $entry->payload['address_id'] )->toBe( $first->id )
        ->and( $fired )->toBeTrue();
} );

it( 'fires address hooks only after the outer transaction commits', function (): void {
    $fired = [];
    foreach ( [ 'addressAdded', 'addressUpdated', 'addressDeleted' ] as $hook ) {
        addAction( 'ap.ecommerce.customer.' . $hook, function () use ( &$fired, $hook ): void {
            $fired[] = $hook;
        } );
    }

    DB::transaction( function () use ( &$fired ): void {
        $address = $this->addresses->create( $this->customer, $this->valid );
        $this->addresses->update( $address, [ 'city' => 'Shelbyville' ] );
        $this->addresses->delete( $address );

        expect( $fired )->toBe( [] );
    } );

    expect( $fired )->toBe( [ 'addressAdded', 'addressUpdated', 'addressDeleted' ] );
} );

it( 'does nothing when deleting an address that is already gone', function (): void {
    $address = $this->addresses->create( $this->customer, $this->valid );
    $stale   = CustomerAddress::query()->find( $address->id );

    $this->addresses->delete( $address );

    $fired = false;
    addAction( 'ap.ecommerce.customer.addressDeleted', function () use ( &$fired ): void {
        $fired = true;
    } );

    $this->addresses->delete( $stale );

    expect( $fired )->toBeFalse()
        ->and( ActivityLogEntry::query()->forSubject( $this->customer )->where( 'event_type', 'address.deleted' )->count() )->toBe( 1 );
} );

it( 'reads string flags as booleans', function ( mixed $flag, bool $expected ): void {
    $this->addresses->create( $this->customer, $this->valid );

    $address = $this->addresses->create( $this->customer, $this->valid + [ 'is_default_shipping' => $flag ] );

    expect( $address->is_default_shipping )->toBe( $expected );
} )->with( [
    'string false' => [ 'false', false ],
    'string 0'     => [ '0', false ],
    'string true'  => [ 'true', true ],
    'int 1'        => [ 1, true ],
] );
