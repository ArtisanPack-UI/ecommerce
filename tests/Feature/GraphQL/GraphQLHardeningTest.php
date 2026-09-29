<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderNote;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\ApiUser;

require_once __DIR__ . '/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Gate::define( 'ecommerce.admin', fn ( $user ): bool => 1 === (int) $user->getAuthIdentifier() );
} );

it( 'never loads admin-only relations for shoppers', function (): void {
    $customer = Customer::factory()->create( [ 'user_id' => 7 ] );
    $order    = Order::factory()->create( [ 'customer_id' => $customer->id ] );
    OrderNote::factory()->create( [ 'order_id' => $order->id, 'body' => 'Staff only: fraud check pending', 'is_customer_visible' => false ] );

    $query = '{ myOrders { nodes { id notes { body } timeline { event_type } edits { reason } } } }';

    Sanctum::actingAs( ApiUser::make( 7 ), [ TokenAbilities::STOREFRONT ] );
    gql( $this, $query )
        ->assertJsonMissingPath( 'errors' )
        ->assertJsonPath( 'data.myOrders.nodes.0.notes', null )
        ->assertJsonPath( 'data.myOrders.nodes.0.timeline', null )
        ->assertJsonPath( 'data.myOrders.nodes.0.edits', null );

    Sanctum::actingAs( ApiUser::make( 1 ), [ TokenAbilities::ADMIN ] );
    gql( $this, '{ orders { nodes { notes { body } } } }' )
        ->assertJsonPath( 'data.orders.nodes.0.notes.0.body', 'Staff only: fraud check pending' );
} );

it( 'does not reveal a cart\'s customer to token holders', function (): void {
    $cart = Cart::factory()->create( [ 'customer_id' => Customer::factory()->create( [ 'email' => 'private@example.test' ] )->id ] );

    gql( $this, 'query ($t: String!) { cart(token: $t) { customer_id customer { email } } }', [ 't' => $cart->token ] )
        ->assertJsonPath( 'data.cart.customer', null )
        ->assertJsonPath( 'data.cart.customer_id', $cart->customer_id );
} );

it( 'rejects mutations over GET', function (): void {
    $this->getJson( '/graphql/ecommerce?query=' . urlencode( 'mutation { createCart(input: {}) { cart { token } } }' ) )
        ->assertOk()
        ->assertJsonPath( 'errors.0.message', 'GET requests may only execute query operations; send mutations with POST.' );

    expect( Cart::query()->count() )->toBe( 0 );

    $this->getJson( '/graphql/ecommerce?query=' . urlencode( '{ products { nodes { id } } }' ) )
        ->assertOk()
        ->assertJsonMissingPath( 'errors' );
} );

it( 'caps query complexity (aliases included)', function (): void {
    config()->set( 'artisanpack.ecommerce.graphql.max_complexity', 20 );

    $aliases = implode( ' ', array_map( static fn ( int $i ): string => "p{$i}: products(first: 1) { nodes { id name } }", range( 1, 10 ) ) );

    gql( $this, '{ ' . $aliases . ' }' )
        ->assertJsonPath( 'data', null )
        ->assertJsonFragment( [ 'message' => 'Max query complexity should be 20 but got 40.' ] );
} );

it( 'multiplies nested lists and connections into the cost', function (): void {
    // products(first: 100) × variants (×5) × product × variants (×5) …
    gql( $this, '{ products(first: 100) { nodes { variants { product { variants { product { variants { id } } } } } } } }' )
        ->assertJsonPath( 'data', null )
        ->assertJsonFragment( [ 'message' => 'Max query complexity should be 5000 but got 18701.' ] );

    // A realistic product page stays well inside the budget.
    gql( $this, '{ products(first: 25) { nodes { name prices { price { amount } } variants { sku prices { price { amount } } } } } }' )
        ->assertJsonMissingPath( 'errors' );
} );

it( 'caps batch size in every body format', function (): void {
    config()->set( 'artisanpack.ecommerce.graphql.max_batch', 2 );

    $this->post( '/graphql/ecommerce', [
        [ 'query' => '{ products { nodes { id } } }' ],
        [ 'query' => '{ products { nodes { id } } }' ],
        [ 'query' => '{ products { nodes { id } } }' ],
    ], [ 'Accept' => 'application/json' ] )->assertStatus( 400 );
} );

it( 'caps batch size', function (): void {
    config()->set( 'artisanpack.ecommerce.graphql.max_batch', 2 );

    $operation = [ 'query' => '{ products { nodes { id } } }' ];

    $this->postJson( '/graphql/ecommerce', [ $operation, $operation, $operation ] )
        ->assertStatus( 400 )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/graphql-batch-too-large' );

    $this->postJson( '/graphql/ecommerce', [ $operation, $operation ] )->assertOk()->assertJsonCount( 2 );
} );

it( 'rejects search cursors that do not line up with the page size', function (): void {
    Product::factory()->count( 3 )->create( [ 'name' => 'Linen Shirt' ] );

    $page = gql( $this, '{ search(query: "shirt", first: 2) { pageInfo { endCursor hasNextPage } } }' )
        ->assertJsonPath( 'data.search.pageInfo.hasNextPage', true );

    gql( $this, 'query ($after: String) { search(query: "shirt", first: 2, after: $after) { nodes { name } } }', [ 'after' => $page->json( 'data.search.pageInfo.endCursor' ) ] )
        ->assertJsonMissingPath( 'errors' )
        ->assertJsonCount( 1, 'data.search.nodes' );

    gql( $this, 'query ($after: String) { search(query: "shirt", first: 3, after: $after) { nodes { name } } }', [ 'after' => $page->json( 'data.search.pageInfo.endCursor' ) ] )
        ->assertJsonPath( 'errors.0.extensions.code', 'BAD_USER_INPUT' );
} );

it( 'keeps the webhook secret out of a stored GraphQL replay', function (): void {
    Sanctum::actingAs( ApiUser::make( 1 ), [ TokenAbilities::ADMIN ] );
    $mutation = 'mutation ($input: CreateWebhookSubscriptionInput!) { createWebhookSubscription(input: $input) { webhook_subscription { id s: secret } } }';
    $input    = [ 'input' => [ 'name' => 'x', 'url' => 'https://hooks.example.test/g', 'events' => [ '*' ] ] ];
    $headers  = [ 'Idempotency-Key' => 'gql-key-1' ];

    $first  = gql( $this, $mutation, $input, $headers );
    $replay = gql( $this, $mutation, $input, $headers )->assertHeader( 'Idempotent-Replay', 'true' );

    $secret = $first->json( 'data.createWebhookSubscription.webhook_subscription.s' );

    expect( $secret )->toStartWith( 'whsec_' )
        ->and( $replay->json( 'errors.0.extensions.code' ) )->toBe( 'IDEMPOTENT_REPLAY_WITHHELD' )
        ->and( $replay->getContent() )->not->toContain( $secret )
        ->and( (string) IdempotencyRecord::query()->value( 'response_body' ) )->not->toContain( $secret )
        ->and( WebhookSubscription::query()->count() )->toBe( 1 );
} );
