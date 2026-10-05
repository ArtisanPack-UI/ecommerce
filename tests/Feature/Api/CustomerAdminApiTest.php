<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Http\Middleware\IdempotencyMiddleware;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerAddress;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'deletes and anonymizes a customer', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'jane@example.com' ] );
    $order    = Order::factory()->forCustomer( $customer )->create( [ 'email' => 'jane@example.com', 'total_amount' => 4_200 ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->deleteJson( "/api/ecommerce/v1/customers/{$customer->id}", [], idem() )
        ->assertOk()
        ->assertExactJson( [ 'data' => [ 'type' => 'customer', 'id' => $customer->id, 'deleted' => true ] ] );

    expect( Customer::query()->find( $customer->id ) )->toBeNull()
        ->and( $order->fresh()->customer_id )->toBeNull()
        ->and( $order->fresh()->email )->toBe( CustomerService::ANONYMIZED_EMAIL )
        ->and( (int) $order->fresh()->total_amount )->toBe( 4_200 );
} );

it( 'requires an Idempotency-Key to delete a customer', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->deleteJson( "/api/ecommerce/v1/customers/{$customer->id}" )
        ->assertStatus( 400 );

    expect( Customer::query()->find( $customer->id ) )->not->toBeNull();
} );

it( 'requires customer.delete to delete a customer', function (): void {
    Gate::define( 'ecommerce.admin', fn (): bool => true );
    Gate::define( 'ecommerce.customer.delete', fn (): bool => false );

    $customer = Customer::factory()->create();

    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->deleteJson( "/api/ecommerce/v1/customers/{$customer->id}", [], idem() )
        ->assertForbidden();

    expect( Customer::query()->find( $customer->id ) )->not->toBeNull();
} );

it( 'adds, updates, and deletes a customer address', function (): void {
    $customer = Customer::factory()->create();

    $id = $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses", [
            'label'        => 'Home',
            'address1'     => '1 Main St',
            'city'         => 'Springfield',
            'country_code' => 'us',
        ], idem() )
        ->assertCreated()
        ->assertJsonPath( 'data.type', 'customerAddress' )
        ->assertJsonPath( 'data.country_code', 'US' )
        ->assertJsonPath( 'data.is_default_shipping', true )
        ->json( 'data.id' );

    $this->patchJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses/{$id}", [ 'city' => 'Shelbyville' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.city', 'Shelbyville' )
        ->assertJsonPath( 'data.address1', '1 Main St' );

    $this->deleteJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses/{$id}", [], idem() )
        ->assertOk();

    expect( CustomerAddress::query()->count() )->toBe( 0 );
} );

it( 'validates an address payload', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses", [ 'label' => 'Home' ], idem() )
        ->assertUnprocessable()
        ->assertJsonPath( 'type', fn ( string $type ): bool => str_ends_with( $type, 'validation-failed' ) );
} );

it( 'maps a service refusal to a customer-write-failed problem', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses", [
            'address1'     => '1 Main St',
            'city'         => 'Springfield',
            'country_code' => '12',
        ], idem() )
        ->assertUnprocessable()
        ->assertJsonPath( 'type', fn ( string $type ): bool => str_ends_with( $type, 'customer-write-failed' ) )
        ->assertJsonPath( 'errors.0.field', 'country_code' );
} );

it( 'scopes address writes to the customer', function (): void {
    $customer = Customer::factory()->create();
    $address  = CustomerAddress::factory()->create( [ 'customer_id' => Customer::factory()->create()->id ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses/{$address->id}", [ 'city' => 'X' ], idem() )
        ->assertNotFound();

    $this->deleteJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses/{$address->id}", [], idem() )
        ->assertNotFound();

    expect( CustomerAddress::query()->find( $address->id ) )->not->toBeNull();
} );

it( 'requires customer.update to write addresses', function (): void {
    Gate::define( 'ecommerce.admin', fn (): bool => true );
    Gate::define( 'ecommerce.customer.update', fn (): bool => false );

    $customer = Customer::factory()->create();

    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses", [
            'address1'     => '1 Main St',
            'city'         => 'Springfield',
            'country_code' => 'US',
        ], idem() )
        ->assertForbidden();
} );

it( 'requires an Idempotency-Key on address writes', function (): void {
    $customer = Customer::factory()->create();
    $address  = CustomerAddress::factory()->create( [ 'customer_id' => $customer->id ] );
    $payload  = [ 'address1' => '1 Main St', 'city' => 'Springfield', 'country_code' => 'US' ];

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->postJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses", $payload )->assertStatus( 400 );
    $this->patchJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses/{$address->id}", [ 'city' => 'X' ] )->assertStatus( 400 );
    $this->deleteJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses/{$address->id}" )->assertStatus( 400 );

    expect( CustomerAddress::query()->count() )->toBe( 1 )
        ->and( $address->fresh()->city )->not->toBe( 'X' );
} );

it( 'moves default shipping between addresses over PATCH', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $first  = $this->postJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses", [ 'address1' => '1 Main St', 'city' => 'Springfield', 'country_code' => 'US' ], idem() )->json( 'data.id' );
    $second = $this->postJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses", [ 'address1' => '2 Side St', 'city' => 'Springfield', 'country_code' => 'US' ], idem() )
        ->assertJsonPath( 'data.is_default_shipping', false )
        ->json( 'data.id' );

    $this->patchJson( "/api/ecommerce/v1/customers/{$customer->id}/addresses/{$second}", [ 'is_default_shipping' => true ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.is_default_shipping', true );

    expect( CustomerAddress::query()->find( $first )->is_default_shipping )->toBeFalse()
        ->and( CustomerAddress::query()->find( $first )->is_default_billing )->toBeTrue()
        ->and( CustomerAddress::query()->find( $second )->is_default_billing )->toBeFalse();
} );

it( 'replays a successful customer delete when it is retried with the same key', function (): void {
    $customer = Customer::factory()->create();
    $headers  = idem();

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $first = $this->deleteJson( "/api/ecommerce/v1/customers/{$customer->id}", [], $headers )->assertOk();

    $this->deleteJson( "/api/ecommerce/v1/customers/{$customer->id}", [], $headers )
        ->assertOk()
        ->assertHeader( IdempotencyMiddleware::REPLAY_HEADER )
        ->assertExactJson( $first->json() );
} );

it( 'still 404s a delete of a missing customer under a fresh key', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->deleteJson( '/api/ecommerce/v1/customers/999999', [], idem() )
        ->assertNotFound();
} );
