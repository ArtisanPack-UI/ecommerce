<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->service = app( CustomerService::class );
} );

it( 'creates a guest customer snapshot on first sighting and fires the registered hook', function (): void {
    $captured = null;
    addAction( 'ap.ecommerce.customer.registered', function ( Customer $customer ) use ( &$captured ): void {
        $captured = $customer->id;
    } );

    $customer = $this->service->findOrCreateForEmail( 'Shopper@Example.com' );

    expect( $customer->exists )->toBeTrue();
    expect( $customer->email )->toBe( 'shopper@example.com' );
    expect( $customer->user_id )->toBeNull();
    expect( $captured )->toBe( $customer->id );
} );

it( 'returns the existing customer without firing the registered hook when the email is already known', function (): void {
    $existing = Customer::factory()->guest()->create( [ 'email' => 'known@example.com' ] );

    $fired = 0;
    addAction( 'ap.ecommerce.customer.registered', function () use ( &$fired ): void {
        ++$fired;
    } );

    $result = $this->service->findOrCreateForEmail( 'KNOWN@example.com' );

    expect( $result->id )->toBe( $existing->id );
    expect( $fired )->toBe( 0 );
    expect( Customer::query()->count() )->toBe( 1 );
} );

it( 'back-fills user_id on a guest customer and fires userLinked', function (): void {
    Customer::factory()->guest()->create( [ 'email' => 'guest@example.com' ] );

    $captured = null;
    addAction( 'ap.ecommerce.customer.userLinked', function ( Customer $customer, Authenticatable $user ) use ( &$captured ): void {
        $captured = [ $customer->id, $user->getAuthIdentifier() ];
    } );

    $user   = makeStubAuthUser( 42, 'guest@example.com' );
    $linked = $this->service->linkUser( $user );

    expect( $linked )->not()->toBeNull();
    expect( $linked->user_id )->toBe( 42 );
    expect( $captured )->toBe( [ $linked->id, 42 ] );
} );

it( 'creates a customer row when linking a user whose email was never seen before', function (): void {
    $registered = null;
    addAction( 'ap.ecommerce.customer.registered', function ( Customer $customer ) use ( &$registered ): void {
        $registered = $customer->id;
    } );

    $user   = makeStubAuthUser( 7, 'brand-new@example.com' );
    $linked = $this->service->linkUser( $user );

    expect( $linked )->not()->toBeNull();
    expect( $linked->user_id )->toBe( 7 );
    expect( $linked->email )->toBe( 'brand-new@example.com' );
    expect( $registered )->toBe( $linked->id );
} );

it( 'is a no-op when the customer is already linked to the same user', function (): void {
    Customer::factory()->create( [ 'email' => 'me@example.com', 'user_id' => 5 ] );

    $fired = 0;
    addAction( 'ap.ecommerce.customer.userLinked', function () use ( &$fired ): void {
        ++$fired;
    } );

    $user   = makeStubAuthUser( 5, 'me@example.com' );
    $result = $this->service->linkUser( $user );

    expect( $result )->not()->toBeNull();
    expect( $result->user_id )->toBe( 5 );
    expect( $fired )->toBe( 0 );
} );

it( 'refuses to overwrite user_id when a different user has already claimed the email', function (): void {
    Customer::factory()->create( [ 'email' => 'shared@example.com', 'user_id' => 1 ] );

    $fired = 0;
    addAction( 'ap.ecommerce.customer.userLinked', function () use ( &$fired ): void {
        ++$fired;
    } );

    $user   = makeStubAuthUser( 2, 'shared@example.com' );
    $result = $this->service->linkUser( $user );

    expect( $result )->toBeNull();
    expect( Customer::query()->where( 'email', 'shared@example.com' )->first()->user_id )->toBe( 1 );
    expect( $fired )->toBe( 0 );
} );

function makeStubAuthUser( int $id, string $email ): Authenticatable
{
    return new class( $id, $email ) implements Authenticatable {
        public function __construct( public int $id, public string $email )
        {
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return $this->id;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken( $value ): void
        {
        }

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };
}
