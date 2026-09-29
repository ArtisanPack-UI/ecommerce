<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Api\ResourceSchemas;
use ArtisanPackUI\Ecommerce\GraphQL\EcommerceSchema;
use ArtisanPackUI\Ecommerce\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rebing\GraphQL\GraphQL;

require_once __DIR__ . '/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

it( 'builds a valid schema with a type, edge, and connection per REST resource', function (): void {
    $schema = app( GraphQL::class )->schema( EcommerceSchema::NAME );

    $schema->assertValid();

    foreach ( array_keys( ResourceSchemas::all() ) as $type ) {
        expect( $schema->hasType( $type ) )->toBeTrue()
            ->and( $schema->hasType( $type . 'Connection' ) )->toBeTrue()
            ->and( $schema->hasType( $type . 'Edge' ) )->toBeTrue();
    }

    expect( $schema->getQueryType()->hasField( 'products' ) )->toBeTrue()
        ->and( $schema->getMutationType()->hasField( 'addToCart' ) )->toBeTrue()
        ->and( $schema->getSubscriptionType()->hasField( 'stockChanged' ) )->toBeTrue();
} );

it( 'is served at /graphql/ecommerce', function (): void {
    Product::factory()->create( [ 'name' => 'Mug' ] );

    gql( $this, '{ products { nodes { name } } }' )
        ->assertOk()
        ->assertJsonPath( 'data.products.nodes.0.name', 'Mug' );
} );

it( 'lets satellites extend types and root fields through the filter', function (): void {
    addFilter( 'ap.ecommerce.graphql.extend', function ( array $schema ): array {
        $schema['types']['Product']['fields']['shout'] = [
            'type'    => 'String',
            'resolve' => fn ( array $product ): string => strtoupper( $product['name'] ),
        ];
        $schema['query']['ping'] = [ 'type' => 'String!', 'resolve' => fn (): string => 'pong' ];

        return $schema;
    } );

    app()->forgetInstance( GraphQL::class );

    $product = Product::factory()->create( [ 'name' => 'Mug' ] );

    gql( $this, 'query ($id: ID!) { ping product(id: $id) { shout } }', [ 'id' => $product->id ] )
        ->assertOk()
        ->assertJsonPath( 'data.ping', 'pong' )
        ->assertJsonPath( 'data.product.shout', 'MUG' );
} );

it( 'rejects queries nested past the depth limit', function (): void {
    config()->set( 'artisanpack.ecommerce.graphql.max_depth', 3 );

    gql( $this, '{ products { nodes { variants { product { variants { id } } } } } }' )
        ->assertOk()
        ->assertJsonPath( 'data', null )
        ->assertJsonFragment( [ 'message' => 'Max query depth should be 3 but got 4.' ] );
} );
