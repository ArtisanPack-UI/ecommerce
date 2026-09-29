<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Api\ResourceSchemas;
use ArtisanPackUI\Ecommerce\Http\Middleware\EnsureEcommerceAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses( RefreshDatabase::class );

it( 'declares exactly the fields each REST resource emits', function ( string $type ): void {
    $schema  = ResourceSchemas::get( $type );
    $request = Request::create( '/' );
    $request->attributes->set( EnsureEcommerceAbility::ADMIN_ATTRIBUTE, true );

    $emitted  = array_keys( ( new $schema['resource']( $schema['model']::factory()->create() ) )->resolve( $request ) );
    $declared = array_keys( $schema['fields'] );

    // `secret` is only rendered on the create response.
    $optional = 'WebhookSubscription' === $type ? [ 'secret' ] : [];

    expect( array_values( array_diff( $emitted, $declared ) ) )->toBe( [] )
        ->and( array_values( array_diff( $declared, $emitted, $optional ) ) )->toBe( [] );
} )->with( fn (): array => array_keys( ResourceSchemas::all() ) );

it( 'declares every relation a resource can include', function ( string $type ): void {
    $schema    = ResourceSchemas::get( $type );
    $resource  = new ReflectionMethod( $schema['resource'], 'relations' );
    $relations = $resource->invoke( new $schema['resource']( new $schema['model']() ) );

    expect( array_keys( $schema['relations'] ) )->toEqualCanonicalizing( array_keys( $relations ) );

    foreach ( $relations as $name => [ $relation, $resourceClass ] ) {
        expect( $schema['relations'][ $name ][2] )->toBe( $relation )
            ->and( $schema['relations'][ $name ][0] )->toBe( ResourceSchemas::typeForResource( $resourceClass ) );
    }
} )->with( fn (): array => array_keys( ResourceSchemas::all() ) );
