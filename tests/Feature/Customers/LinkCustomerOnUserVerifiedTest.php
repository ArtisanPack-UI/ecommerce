<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses( RefreshDatabase::class );

it( 'links a guest customer to the user when the Verified event fires', function (): void {
    Customer::factory()->guest()->create( [ 'email' => 'verify@example.com' ] );

    $user = new class implements Authenticatable {
        public string $email = 'verify@example.com';

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 99;
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

    Event::dispatch( new Verified( $user ) );

    $customer = Customer::query()->where( 'email', 'verify@example.com' )->firstOrFail();
    expect( $customer->user_id )->toBe( 99 );
} );
