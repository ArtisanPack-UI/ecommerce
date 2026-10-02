<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Gate::define( 'ecommerce.admin', fn ( $user ): bool => 1 === (int) $user->getAuthIdentifier() );
} );

it( 'lists sub-statuses, optionally for one system status', function (): void {
    $this->actingAs( new GenericUser( [ 'id' => 1 ] ), 'sanctum' );

    gql( $this, '{ orderSubstatuses { key system_status } }' )
        ->assertJsonCount( 6, 'data.orderSubstatuses' )
        ->assertJsonPath( 'data.orderSubstatuses.0.system_status', 'pending' );

    gql( $this, '{ orderSubstatuses(systemStatus: "complete") { key is_terminal } }' )
        ->assertJsonCount( 1, 'data.orderSubstatuses' )
        ->assertJsonPath( 'data.orderSubstatuses.0.is_terminal', true );
} );

it( 'refuses sub-status fields without the abilities', function (): void {
    $this->actingAs( ecommerceShopperUser(), 'sanctum' );

    gql( $this, '{ orderSubstatuses { key } }' )->assertJsonPath( 'errors.0.extensions.code', 'FORBIDDEN' );
    gql( $this, 'mutation ($input: CreateOrderSubstatusInput!) { createOrderSubstatus(input: $input) { substatus { id } } }', [
        'input' => [ 'system_status' => 'processing', 'label' => 'Printing' ],
    ] )->assertJsonPath( 'errors.0.extensions.code', 'FORBIDDEN' );

    expect( OrderSubstatus::query()->where( 'key', 'printing' )->exists() )->toBeFalse();
} );

it( 'creates, updates, reorders, and deletes a sub-status', function (): void {
    $this->actingAs( new GenericUser( [ 'id' => 1 ] ), 'sanctum' );

    $id = gql( $this, 'mutation ($input: CreateOrderSubstatusInput!) { createOrderSubstatus(input: $input) { substatus { id key color } errors { code } } }', [
        'input' => [ 'system_status' => 'processing', 'label' => 'Printing', 'color' => '#00ff00' ],
    ] )
        ->assertJsonPath( 'data.createOrderSubstatus.substatus.key', 'printing' )
        ->assertJsonPath( 'data.createOrderSubstatus.substatus.color', '#00FF00' )
        ->json( 'data.createOrderSubstatus.substatus.id' );

    gql( $this, 'mutation ($input: UpdateOrderSubstatusInput!) { updateOrderSubstatus(input: $input) { substatus { label } } }', [
        'input' => [ 'id' => $id, 'label' => 'Press' ],
    ] )->assertJsonPath( 'data.updateOrderSubstatus.substatus.label', 'Press' );

    gql( $this, 'mutation ($input: ReorderOrderSubstatusesInput!) { reorderOrderSubstatuses(input: $input) { substatuses { key } } }', [
        'input' => [ 'system_status' => 'processing', 'ids' => [ $id ] ],
    ] )->assertJsonPath( 'data.reorderOrderSubstatuses.substatuses.0.key', 'printing' );

    gql( $this, 'mutation ($input: DeleteOrderSubstatusInput!) { deleteOrderSubstatus(input: $input) { deleted_id } }', [
        'input' => [ 'id' => $id ],
    ] )->assertJsonPath( 'data.deleteOrderSubstatus.deleted_id', (string) $id );

    expect( OrderSubstatus::query()->find( $id ) )->toBeNull();
} );

it( 'returns refusals as user errors', function (): void {
    $this->actingAs( new GenericUser( [ 'id' => 1 ] ), 'sanctum' );

    $substatus = OrderSubstatus::factory()->create( [ 'system_status' => 'processing' ] );
    Order::factory()->withSystemStatus( 'processing' )->create( [ 'substatus_id' => $substatus->id ] );

    gql( $this, 'mutation ($input: DeleteOrderSubstatusInput!) { deleteOrderSubstatus(input: $input) { deleted_id errors { code } } }', [
        'input' => [ 'id' => $substatus->id ],
    ] )->assertJsonPath( 'data.deleteOrderSubstatus.errors.0.code', 'substatus-in-use' );

    gql( $this, 'mutation ($input: CreateOrderSubstatusInput!) { createOrderSubstatus(input: $input) { errors { field code } } }', [
        'input' => [ 'system_status' => 'nope', 'label' => 'X' ],
    ] )->assertJsonPath( 'data.createOrderSubstatus.errors.0.code', 'unknown-system-status' );
} );
