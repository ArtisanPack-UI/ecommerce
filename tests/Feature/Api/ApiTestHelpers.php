<?php

declare( strict_types=1 );

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;

if ( ! function_exists( 'ecommerceAdmin' ) ) {
    /**
     * Grants the umbrella `ecommerce.admin` ability to user id 1 and returns
     * that user.
     */
    function ecommerceAdmin(): GenericUser
    {
        Gate::define( 'ecommerce.admin', fn ( $user ): bool => 1 === (int) $user->getAuthIdentifier() );

        return new GenericUser( [ 'id' => 1, 'name' => 'Admin' ] );
    }
}

if ( ! function_exists( 'ecommerceShopper' ) ) {
    /**
     * A signed-in user with no ecommerce abilities.
     */
    function ecommerceShopper(): GenericUser
    {
        return new GenericUser( [ 'id' => 2, 'name' => 'Shopper' ] );
    }
}

if ( ! function_exists( 'idem' ) ) {
    /**
     * Headers for a mutating request.
     *
     * @return array<string, string>
     */
    function idem(): array
    {
        return [ 'Idempotency-Key' => (string) Illuminate\Support\Str::uuid(), 'Accept' => 'application/json' ];
    }
}
