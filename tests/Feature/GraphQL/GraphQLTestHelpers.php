<?php

declare( strict_types=1 );

if ( ! function_exists( 'gql' ) ) {
    /**
     * POSTs a GraphQL operation to the ecommerce schema.
     *
     * @param  array<string, mixed>   $variables
     * @param  array<string, string>  $headers
     */
    function gql( $test, string $query, array $variables = [], array $headers = [] ): Illuminate\Testing\TestResponse
    {
        return $test->postJson( '/graphql/ecommerce', [ 'query' => $query, 'variables' => (object) $variables ], $headers );
    }
}

if ( ! function_exists( 'ecommerceShopperUser' ) ) {
    /**
     * A signed-in user with no ecommerce abilities.
     */
    function ecommerceShopperUser(): Illuminate\Auth\GenericUser
    {
        return new Illuminate\Auth\GenericUser( [ 'id' => 2, 'name' => 'Shopper' ] );
    }
}
