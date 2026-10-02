<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'lists sub-statuses filtered by system status', function (): void {
    OrderSubstatus::factory()->create( [ 'system_status' => 'processing', 'key' => 'printing', 'position' => 5 ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( '/api/ecommerce/v1/admin/order-substatuses?filter[system_status]=processing' )
        ->assertOk()
        ->assertJsonCount( 2, 'data' )
        ->assertJsonPath( 'data.0.type', 'orderSubstatus' )
        ->assertJsonPath( 'data.0.key', 'in-progress' )
        ->assertJsonPath( 'data.1.key', 'printing' );
} );

it( 'refuses every sub-status route without the orderSubstatus abilities', function (): void {
    $substatus = OrderSubstatus::factory()->create();

    $this->actingAs( ecommerceShopper(), 'sanctum' );

    $this->getJson( '/api/ecommerce/v1/admin/order-substatuses' )->assertForbidden();
    $this->postJson( '/api/ecommerce/v1/admin/order-substatuses', [ 'system_status' => 'processing', 'label' => 'X' ], idem() )->assertForbidden();
    $this->postJson( '/api/ecommerce/v1/admin/order-substatuses/reorder', [ 'system_status' => 'processing', 'ids' => [ $substatus->id ] ], idem() )->assertForbidden();
    $this->patchJson( "/api/ecommerce/v1/admin/order-substatuses/{$substatus->id}", [ 'label' => 'Y' ], idem() )->assertForbidden();
    $this->deleteJson( "/api/ecommerce/v1/admin/order-substatuses/{$substatus->id}", [], idem() )->assertForbidden();

    expect( $substatus->fresh()->label )->not->toBe( 'Y' );
} );

it( 'honours the specific orderSubstatus ability over the umbrella', function (): void {
    Gate::define( 'ecommerce.admin', fn (): bool => true );
    Gate::define( 'ecommerce.orderSubstatus.create', fn (): bool => false );

    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->postJson( '/api/ecommerce/v1/admin/order-substatuses', [ 'system_status' => 'processing', 'label' => 'X' ], idem() )
        ->assertForbidden();
} );

it( 'creates, updates, reorders, and deletes a sub-status', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $id = $this->postJson( '/api/ecommerce/v1/admin/order-substatuses', [
        'system_status' => 'processing',
        'label'         => 'Printing',
        'color'         => '#a855f7',
    ], idem() )
        ->assertCreated()
        ->assertJsonPath( 'data.key', 'printing' )
        ->assertJsonPath( 'data.color', '#A855F7' )
        ->json( 'data.id' );

    $this->patchJson( "/api/ecommerce/v1/admin/order-substatuses/{$id}", [ 'label' => 'On the press', 'is_terminal' => true ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.label', 'On the press' )
        ->assertJsonPath( 'data.is_terminal', true )
        ->assertJsonPath( 'data.key', 'printing' );

    $this->postJson( '/api/ecommerce/v1/admin/order-substatuses/reorder', [ 'system_status' => 'processing', 'ids' => [ $id ] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.0.id', $id )
        ->assertJsonPath( 'data.0.position', 0 );

    $this->deleteJson( "/api/ecommerce/v1/admin/order-substatuses/{$id}", [], idem() )->assertOk();

    expect( OrderSubstatus::query()->find( $id ) )->toBeNull();
} );

it( 'requires an Idempotency-Key on writes', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( '/api/ecommerce/v1/admin/order-substatuses', [ 'system_status' => 'processing', 'label' => 'X' ] )
        ->assertStatus( 400 );
} );

it( 'validates the payload shape', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( '/api/ecommerce/v1/admin/order-substatuses', [ 'label' => 'X' ], idem() )
        ->assertUnprocessable()
        ->assertJsonPath( 'type', fn ( string $type ): bool => str_ends_with( $type, 'validation-failed' ) );
} );

it( 'renders service refusals as substatus-write-failed problems', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( '/api/ecommerce/v1/admin/order-substatuses', [ 'system_status' => 'processing', 'label' => 'X', 'color' => 'red' ], idem() )
        ->assertUnprocessable()
        ->assertJsonPath( 'type', fn ( string $type ): bool => str_ends_with( $type, 'substatus-write-failed' ) )
        ->assertJsonPath( 'errors.0.field', 'color' )
        ->assertJsonPath( 'errors.0.code', 'invalid-color' );
} );

it( 'refuses to delete a sub-status orders use', function (): void {
    $substatus = OrderSubstatus::factory()->create( [ 'system_status' => 'processing' ] );
    Order::factory()->withSystemStatus( 'processing' )->create( [ 'substatus_id' => $substatus->id ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->deleteJson( "/api/ecommerce/v1/admin/order-substatuses/{$substatus->id}", [], idem() )
        ->assertUnprocessable()
        ->assertJsonPath( 'errors.0.code', 'substatus-in-use' );

    expect( OrderSubstatus::query()->find( $substatus->id ) )->not->toBeNull();
} );
