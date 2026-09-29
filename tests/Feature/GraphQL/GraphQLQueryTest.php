<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Auth\ServiceSignature;
use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductAttribute;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\ApiUser;

require_once __DIR__ . '/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Gate::define( 'ecommerce.admin', fn ( $user ): bool => 1 === (int) $user->getAuthIdentifier() );
} );

/**
 * A product with `$variants` priced variants and one attribute.
 */
function catalogProduct( int $variants ): Product
{
    $product = Product::factory()->create( [ 'meta' => [ 'secret' => 'admin-only' ] ] );
    ProductPrice::factory()->forPriceable( $product )->create( [ 'price_amount' => 1_500 ] );
    ProductPrice::factory()->forPriceable( $product )->create( [ 'price_amount' => 999, 'starts_at' => Carbon::now()->addWeek() ] );
    ProductAttribute::factory()->create( [ 'product_id' => $product->id ] );

    foreach ( range( 1, $variants ) as $position ) {
        $variant = ProductVariant::factory()->create( [ 'product_id' => $product->id, 'position' => $position ] );
        ProductPrice::factory()->forPriceable( $variant )->create( [ 'price_amount' => 2_000 + $position ] );
    }

    return $product;
}

const PRODUCT_PAGE_QUERY = 'query ($id: ID!) {
    product(id: $id) {
        id name meta
        prices { price { amount currency } }
        variants { sku prices { price { amount } } }
        attributes { key values { value } }
    }
}';

it( 'fetches a product with variants, prices, and attributes in a fixed number of queries', function (): void {
    $small = catalogProduct( 1 );
    $large = catalogProduct( 5 );

    $count = function ( Product $product ): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        gql( $this, PRODUCT_PAGE_QUERY, [ 'id' => $product->id ] )->assertOk()->assertJsonMissingPath( 'errors' );

        return count( DB::getQueryLog() );
    };

    expect( $count( $small ) )->toBe( $count( $large ) );

    gql( $this, PRODUCT_PAGE_QUERY, [ 'id' => $large->id ] )
        ->assertJsonCount( 5, 'data.product.variants' )
        ->assertJsonPath( 'data.product.variants.0.prices.0.price.amount', 2_001 )
        ->assertJsonCount( 1, 'data.product.prices' )
        ->assertJsonPath( 'data.product.prices.0.price.amount', 1_500 )
        ->assertJsonPath( 'data.product.meta', null );
} );

it( 'hides products the storefront cannot see', function (): void {
    $draft = Product::factory()->draft()->create();

    gql( $this, 'query ($id: ID!) { product(id: $id) { id } }', [ 'id' => $draft->id ] )
        ->assertJsonPath( 'data.product', null );
} );

it( 'pages products as a Relay connection with filters', function (): void {
    Product::factory()->count( 3 )->create( [ 'type' => 'simple' ] );
    Product::factory()->create( [ 'type' => 'digital' ] );

    $first = gql( $this, '{ products(first: 2, filter: { type: "simple" }) { edges { cursor node { product_type } } pageInfo { hasNextPage endCursor } } }' )
        ->assertOk()
        ->assertJsonCount( 2, 'data.products.edges' )
        ->assertJsonPath( 'data.products.pageInfo.hasNextPage', true );

    gql( $this, 'query ($after: String) { products(first: 2, after: $after, filter: { type: "simple" }) { nodes { product_type } pageInfo { hasNextPage } } }', [
        'after' => $first->json( 'data.products.pageInfo.endCursor' ),
    ] )->assertJsonCount( 1, 'data.products.nodes' )
        ->assertJsonPath( 'data.products.pageInfo.hasNextPage', false );
} );

it( 'searches through Scout', function (): void {
    Product::factory()->create( [ 'name' => 'Linen Shirt' ] );
    Product::factory()->create( [ 'name' => 'Wool Hat' ] );

    gql( $this, '{ search(query: "shirt") { nodes { name } pageInfo { hasNextPage } } }' )
        ->assertOk()
        ->assertJsonCount( 1, 'data.search.nodes' )
        ->assertJsonPath( 'data.search.nodes.0.name', 'Linen Shirt' );
} );

it( 'reads a cart by token', function (): void {
    $cart = Cart::factory()->create();

    gql( $this, 'query ($token: String!) { cart(token: $token) { token total { amount currency } items { id } } }', [ 'token' => $cart->token ] )
        ->assertJsonPath( 'data.cart.token', $cart->token )
        ->assertJsonPath( 'data.cart.total.currency', $cart->currency );
} );

it( 'guards admin fields and reveals admin-only fields only to them', function (): void {
    Order::factory()->create( [ 'ip_address' => '203.0.113.7' ] );
    $query = '{ orders { nodes { order_number ip_address } } }';

    gql( $this, $query )->assertJsonPath( 'errors.0.extensions.code', 'UNAUTHENTICATED' );

    $this->actingAs( ecommerceShopperUser(), 'sanctum' );
    gql( $this, $query )->assertJsonPath( 'errors.0.extensions.code', 'FORBIDDEN' );

    Sanctum::actingAs( ApiUser::make( 1 ), [ TokenAbilities::ADMIN ] );
    gql( $this, $query )->assertJsonMissingPath( 'errors' )->assertJsonPath( 'data.orders.nodes.0.ip_address', '203.0.113.7' );
} );

it( 'does not leak admin fields into public fields of the same query', function (): void {
    $product = Product::factory()->create( [ 'meta' => [ 'cost_notes' => 'secret' ] ] );
    Order::factory()->create();
    Sanctum::actingAs( ApiUser::make( 1 ), [ TokenAbilities::ADMIN ] );

    gql( $this, 'query ($id: ID!) { orders { nodes { id } } product(id: $id) { meta } }', [ 'id' => $product->id ] )
        ->assertJsonMissingPath( 'errors' )
        ->assertJsonPath( 'data.product.meta', null );
} );

it( 'applies token scopes to GraphQL fields', function (): void {
    Order::factory()->create();
    Sanctum::actingAs( ApiUser::make( 1 ), [ 'ecommerce:orders.read' ] );

    gql( $this, '{ orders { nodes { id } } }' )->assertJsonMissingPath( 'errors' );
    gql( $this, '{ customers { nodes { id } } }' )->assertJsonPath( 'errors.0.extensions.code', 'FORBIDDEN' );
} );

it( 'authenticates signed service calls', function (): void {
    config()->set( 'artisanpack.ecommerce.api.services', [ 'erp' => [ 'secret' => 'erp-secret-value', 'abilities' => [ 'ecommerce:orders.read' ] ] ] );
    Order::factory()->create();

    $data = [ 'query' => '{ orders { nodes { id } } }', 'variables' => (object) [] ];

    $this->postJson( '/graphql/ecommerce', $data, ServiceSignature::sign( 'erp', 'erp-secret-value', 'POST', '/graphql/ecommerce', 'localhost', (string) json_encode( $data ) ) )
        ->assertOk()
        ->assertJsonMissingPath( 'errors' )
        ->assertJsonCount( 1, 'data.orders.nodes' );
} );

it( 'lets shoppers read themselves and their own orders only', function (): void {
    $me    = Customer::factory()->create( [ 'user_id' => 7, 'email' => 'me@example.test' ] );
    $mine  = Order::factory()->create( [ 'customer_id' => $me->id ] );
    $other = Order::factory()->create( [ 'customer_id' => Customer::factory()->create( [ 'user_id' => 8 ] )->id ] );

    Sanctum::actingAs( ApiUser::make( 7 ), [ TokenAbilities::STOREFRONT ] );

    gql( $this, '{ me { email } myOrders { nodes { id } } }' )
        ->assertJsonPath( 'data.me.email', 'me@example.test' )
        ->assertJsonCount( 1, 'data.myOrders.nodes' )
        ->assertJsonPath( 'data.myOrders.nodes.0.id', (string) $mine->id );

    $order = 'query ($id: ID!) { order(id: $id) { id ip_address } }';

    gql( $this, $order, [ 'id' => $mine->id ] )->assertJsonPath( 'data.order.id', (string) $mine->id )->assertJsonPath( 'data.order.ip_address', null );
    gql( $this, $order, [ 'id' => $other->id ] )->assertJsonPath( 'errors.0.extensions.code', 'FORBIDDEN' );
    gql( $this, $order, [ 'id' => 999_999 ] )->assertJsonPath( 'errors.0.extensions.code', 'FORBIDDEN' );

    Sanctum::actingAs( ApiUser::make( 7 ), [ 'ecommerce:products.read' ] );
    gql( $this, '{ me { email } }' )->assertJsonPath( 'errors.0.extensions.code', 'FORBIDDEN' );
} );

it( 'rate-limits fields with their REST policy', function (): void {
    config()->set( 'artisanpack.ecommerce.rate_limits.catalog.read.per_ip', 1 );
    ArtisanPackUI\Ecommerce\Support\RateLimitPolicyRegistrar::register();

    gql( $this, '{ products { nodes { id } } }' )->assertJsonMissingPath( 'errors' );
    gql( $this, '{ products { nodes { id } } }' )->assertJsonPath( 'errors.0.extensions.code', 'RATE_LIMITED' );
} );
