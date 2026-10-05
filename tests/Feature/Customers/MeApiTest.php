<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerAddress;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderNote;
use ArtisanPackUI\Ecommerce\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\ApiUser;

require_once __DIR__ . '/../Api/ApiTestHelpers.php';
require_once __DIR__ . '/../GraphQL/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

function meUser( int $id = 5, string $email = 'ada@example.test' ): ApiUser
{
    $user         = new ApiUser( [ 'id' => $id, 'email' => $email ] );
    $user->exists = true;

    return $user;
}

beforeEach( function (): void {
    $this->customer = Customer::factory()->forUser( 5 )->create( [ 'email' => 'ada@example.test' ] );
    $this->other    = Customer::factory()->forUser( 6 )->create( [ 'email' => 'eve@example.test' ] );

    Sanctum::actingAs( meUser(), [ TokenAbilities::STOREFRONT ] );
} );

describe( 'profile', function (): void {
    it( 'reads and updates the profile, recording marketing consent', function (): void {
        $this->getJson( '/api/ecommerce/v1/me' )->assertOk()->assertJsonPath( 'data.email', 'ada@example.test' );

        $this->patchJson( '/api/ecommerce/v1/me', [ 'first_name' => 'Ada', 'accepts_marketing' => true, 'email' => 'nope@example.test' ], idem() )
            ->assertOk()
            ->assertJsonPath( 'data.first_name', 'Ada' );

        $customer = $this->customer->refresh();

        expect( $customer->accepts_marketing )->toBeTrue()
            ->and( $customer->accepts_marketing_at )->not->toBeNull()
            ->and( $customer->email )->toBe( 'ada@example.test' );
    } );

    it( 'needs a storefront token', function (): void {
        Sanctum::actingAs( meUser(), [ 'ecommerce:products.read' ] );

        $this->getJson( '/api/ecommerce/v1/me' )->assertForbidden();
    } );

    it( 'never renders staff-only customer meta to the shopper', function (): void {
        $this->customer->forceFill( [ 'meta' => [ 'vip' => [ 'reason' => 'order_count' ] ] ] )->save();

        $this->getJson( '/api/ecommerce/v1/me' )->assertOk()->assertJsonPath( 'data.meta', [] );
    } );
} );

describe( 'addresses', function (): void {
    it( 'saves, updates, and deletes my addresses but never someone else\'s', function (): void {
        $id = $this->postJson( '/api/ecommerce/v1/me/addresses', [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'address1' => '1 Main St', 'city' => 'Chicago', 'postal_code' => '60601', 'country_code' => 'US', 'is_default_shipping' => true,
        ], idem() )->assertCreated()->json( 'data.id' );

        $this->patchJson( "/api/ecommerce/v1/me/addresses/{$id}", [ 'city' => 'Evanston' ], idem() )->assertOk()->assertJsonPath( 'data.city', 'Evanston' );
        $this->getJson( '/api/ecommerce/v1/me/addresses' )->assertOk()->assertJsonCount( 1, 'data' );

        $theirs = CustomerAddress::factory()->create( [ 'customer_id' => $this->other->id ] );

        $this->patchJson( "/api/ecommerce/v1/me/addresses/{$theirs->id}", [ 'city' => 'X' ], idem() )->assertNotFound();
        $this->deleteJson( "/api/ecommerce/v1/me/addresses/{$theirs->id}", [], idem() )->assertNotFound();
        $this->deleteJson( "/api/ecommerce/v1/me/addresses/{$id}", [], idem() )->assertOk();

        expect( CustomerAddress::query()->where( 'customer_id', $this->customer->id )->count() )->toBe( 0 );
    } );
} );

describe( 'orders', function (): void {
    it( 'lists only my orders, filtered by status group', function (): void {
        $open = Order::factory()->create( [ 'customer_id' => $this->customer->id, 'system_status' => 'processing' ] );
        Order::factory()->create( [ 'customer_id' => $this->customer->id, 'system_status' => 'cancelled' ] );
        Order::factory()->create( [ 'customer_id' => $this->other->id ] );

        $this->getJson( '/api/ecommerce/v1/me/orders' )->assertOk()->assertJsonCount( 2, 'data' );
        $this->getJson( '/api/ecommerce/v1/me/orders?filter[status]=open' )->assertOk()->assertJsonCount( 1, 'data' )->assertJsonPath( 'data.0.id', $open->id );
    } );

    it( 'shows my order with its refunds and customer-visible notes, without staff internals (F1)', function (): void {
        $order = Order::factory()->create( [ 'customer_id' => $this->customer->id, 'meta' => [ 'fraud_decision' => [ 'verdict' => 'challenge' ], 'tax_breakdown' => [] ] ] );
        Refund::factory()->create( [ 'order_id' => $order->id, 'reason' => 'Chargeback risk', 'issued_by_user_id' => 1, 'status' => 'succeeded' ] );
        OrderNote::query()->create( [ 'order_id' => $order->id, 'body' => 'Shipped early', 'is_customer_visible' => true, 'author_user_id' => 1 ] );
        OrderNote::query()->create( [ 'order_id' => $order->id, 'body' => 'Fraud check pending', 'is_customer_visible' => false, 'author_user_id' => 1 ] );

        $response = $this->getJson( "/api/ecommerce/v1/me/orders/{$order->id}?include=refunds,customer_notes" )->assertOk();

        expect( $response->json( 'data.meta' ) )->not->toHaveKey( 'fraud_decision' )
            ->and( $response->json( 'data.refunds.0' ) )->not->toHaveKeys( [ 'reason', 'issued_by_user_id', 'gateway_reference' ] )
            ->and( $response->json( 'data.customer_notes' ) )->toHaveCount( 1 )
            ->and( $response->json( 'data.customer_notes.0.body' ) )->toBe( 'Shipped early' );


        $theirs = Order::factory()->create( [ 'customer_id' => $this->other->id ] );
        $this->getJson( "/api/ecommerce/v1/me/orders/{$theirs->id}" )->assertNotFound();
    } );

    it( 'hides fraud verdicts in myOrders but shows them to staff (F1)', function (): void {
        Order::factory()->create( [ 'customer_id' => $this->customer->id, 'meta' => [ 'fraud_decision' => [ 'verdict' => 'challenge' ] ] ] );

        gql( $this, '{ myOrders { nodes { meta } } }' )->assertJsonPath( 'data.myOrders.nodes.0.meta', [] );

        Illuminate\Support\Facades\Gate::define( 'ecommerce.admin', fn ( $user ): bool => 1 === (int) $user->getAuthIdentifier() );
        Sanctum::actingAs( ApiUser::make( 1 ), [ TokenAbilities::ADMIN ] );

        gql( $this, '{ orders { nodes { meta } } }' )->assertJsonPath( 'data.orders.nodes.0.meta.fraud_decision.verdict', 'challenge' );
    } );
} );

describe( 'claims', function (): void {
    it( 'claims guest orders under my email with an order number and postal code', function (): void {
        $guest = Order::factory()->guest()->create( [ 'email' => 'ada@example.test', 'shipping_address' => [ 'postal_code' => '60601', 'country_code' => 'US' ] ] );

        $this->postJson( '/api/ecommerce/v1/me/claims', [ 'order_number' => $guest->order_number, 'postal_code' => '00000' ], idem() )
            ->assertStatus( 422 )
            ->assertJsonPath( 'type', fn ( string $type ) => str_ends_with( $type, '/claim-not-verified' ) );

        $this->postJson( '/api/ecommerce/v1/me/claims', [ 'order_number' => $guest->order_number, 'postal_code' => '60601' ], idem() )
            ->assertOk()
            ->assertJsonPath( 'data.0.id', $guest->id );

        expect( $guest->refresh()->customer_id )->toBe( $this->customer->id );
    } );

    it( 'stops after the claim limit', function (): void {
        config()->set( 'artisanpack.ecommerce.customers.claim_rate_limit', 2 );

        foreach ( range( 1, 2 ) as $attempt ) {
            $this->postJson( '/api/ecommerce/v1/me/claims', [ 'order_number' => 'NOPE' . $attempt, 'postal_code' => '1' ], idem() )->assertStatus( 422 );
        }

        $this->postJson( '/api/ecommerce/v1/me/claims', [ 'order_number' => 'NOPE3', 'postal_code' => '1' ], idem() )->assertStatus( 429 );
    } );
} );
