<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Listeners\LinkCustomerOnUserVerified;
use ArtisanPackUI\Ecommerce\Models\Customer;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\ApiUser;

uses( RefreshDatabase::class );

/**
 * An in-memory user with `$id` and `$email`.
 */
function covVerifiedUser( int $id, ?string $email ): ApiUser
{
    $user        = ApiUser::make( $id );
    $user->email = $email;

    return $user;
}

it( 'links the guest customer with the verified email', function (): void {
    $guest = Customer::factory()->guest()->create( [ 'email' => 'ada@example.test' ] );

    app( LinkCustomerOnUserVerified::class )->handle( new Verified( covVerifiedUser( 41, 'ADA@example.test' ) ) );

    expect( $guest->fresh()->user_id )->toBe( 41 );
} );

it( 'creates a customer for a verified user with no orders yet', function (): void {
    app( LinkCustomerOnUserVerified::class )->handle( new Verified( covVerifiedUser( 42, 'new@example.test' ) ) );

    expect( Customer::query()->where( 'email', 'new@example.test' )->value( 'user_id' ) )->toBe( 42 );
} );

it( 'keeps an existing link and never steals another user\'s customer', function (): void {
    $mine   = Customer::factory()->create( [ 'email' => 'old@example.test', 'user_id' => 43 ] );
    $theirs = Customer::factory()->create( [ 'email' => 'shared@example.test', 'user_id' => 44 ] );

    app( LinkCustomerOnUserVerified::class )->handle( new Verified( covVerifiedUser( 43, 'renamed@example.test' ) ) );
    app( LinkCustomerOnUserVerified::class )->handle( new Verified( covVerifiedUser( 45, 'shared@example.test' ) ) );

    expect( $mine->fresh()->user_id )->toBe( 43 )
        ->and( Customer::query()->where( 'email', 'renamed@example.test' )->exists() )->toBeFalse()
        ->and( $theirs->fresh()->user_id )->toBe( 44 )
        ->and( Customer::query()->where( 'user_id', 45 )->exists() )->toBeFalse();
} );

it( 'ignores a user without an email and one that isn\'t an auth user', function (): void {
    app( LinkCustomerOnUserVerified::class )->handle( new Verified( covVerifiedUser( 46, null ) ) );

    // Not an auth user; the framework trait keeps the contract complete on
    // every supported Laravel version.
    $notAuthenticatable = new class implements MustVerifyEmail {
        use MustVerifyEmailTrait;

        public string $email = 'ghost@example.test';

        public function forceFill( array $attributes ): static
        {
            return $this;
        }

        public function save(): bool
        {
            return true;
        }
    };

    app( LinkCustomerOnUserVerified::class )->handle( new Verified( $notAuthenticatable ) );

    expect( Customer::query()->count() )->toBe( 0 );
} );
