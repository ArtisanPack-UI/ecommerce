<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Http\Resources\ProductResource;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'lists visible products under /api/ecommerce/v1 with cursor pagination', function (): void {
    Product::factory()->count( 3 )->create();
    Product::factory()->draft()->create();
    Product::factory()->create( [ 'published_at' => Carbon::now()->addDay() ] );

    $first = $this->getJson( '/api/ecommerce/v1/products?per_page=2' )->assertOk();

    expect( $first->json( 'data' ) )->toHaveCount( 2 );
    expect( $first->json( 'data.0.type' ) )->toBe( 'product' );
    expect( $first->json( 'meta.next_cursor' ) )->not->toBeNull();

    $second = $this->getJson( $first->json( 'links.next' ) )->assertOk();

    expect( $second->json( 'data' ) )->toHaveCount( 1 );
    expect( $second->json( 'meta.next_cursor' ) )->toBeNull();
} );

it( 'eager-loads variants via include and filters + sorts', function (): void {
    $shirt = Product::factory()->create( [ 'name' => 'Shirt', 'type' => 'simple' ] );
    Product::factory()->create( [ 'name' => 'Album', 'type' => 'digital' ] );
    ProductVariant::factory()->count( 2 )->create( [ 'product_id' => $shirt->id ] );

    $response = $this->getJson( '/api/ecommerce/v1/products?include=variants&filter[type]=simple&sort=name' )->assertOk();

    expect( $response->json( 'data' ) )->toHaveCount( 1 );
    expect( $response->json( 'data.0.name' ) )->toBe( 'Shirt' );
    expect( $response->json( 'data.0.variants' ) )->toHaveCount( 2 );
    expect( $response->json( 'data.0.variants.0.type' ) )->toBe( 'variant' );

    $names = $this->getJson( '/api/ecommerce/v1/products?sort=-name' )->json( 'data.*.name' );
    expect( $names )->toBe( [ 'Shirt', 'Album' ] );
} );

it( 'rejects unknown include, filter, and sort parameters as problem+json', function ( string $query, string $field ): void {
    $this->getJson( '/api/ecommerce/v1/products?' . $query )
        ->assertStatus( 400 )
        ->assertHeader( 'Content-Type', 'application/problem+json' )
        ->assertJsonPath( 'errors.0.field', $field );
} )->with( [
    'include' => [ 'include=images', 'include' ],
    'filter'  => [ 'filter[cost]=1', 'filter' ],
    'sort'    => [ 'sort=cost_amount', 'sort' ],
] );

it( 'shows a product and hides drafts', function (): void {
    $product = Product::factory()->create();
    $draft   = Product::factory()->draft()->create();

    $this->getJson( "/api/ecommerce/v1/products/{$product->id}" )->assertOk()->assertJsonPath( 'data.slug', $product->slug );
    $this->getJson( "/api/ecommerce/v1/products/{$draft->id}" )->assertNotFound();
} );

it( 'lists a product\'s variants', function (): void {
    $product = Product::factory()->create();
    ProductVariant::factory()->create( [ 'product_id' => $product->id, 'position' => 2, 'sku' => 'B' ] );
    ProductVariant::factory()->create( [ 'product_id' => $product->id, 'position' => 1, 'sku' => 'A' ] );

    $this->getJson( "/api/ecommerce/v1/products/{$product->id}/variants" )
        ->assertOk()
        ->assertJsonPath( 'data.*.sku', [ 'A', 'B' ] );
} );

it( 'lets satellites decorate a resource and a listing through filters', function (): void {
    Product::factory()->create();

    addFilter( 'ap.ecommerce.api.resource.product', function ( array $data ): array {
        $data['badge'] = 'new';

        return $data;
    } );
    addFilter( 'ap.ecommerce.api.list.product', fn ( array $items ) => array_map( fn ( $item ) => $item + [ 'listed' => true ], $items ) );

    $this->getJson( '/api/ecommerce/v1/products' )
        ->assertJsonPath( 'data.0.badge', 'new' )
        ->assertJsonPath( 'data.0.listed', true );
} );

it( 'returns a cart by token with money as { amount, currency }', function (): void {
    $cart = cartWithLines( [ [ 'unit' => 1_250, 'qty' => 2 ] ], 'EUR', [ 'subtotal_amount' => 2_500 ] );

    $this->getJson( "/api/ecommerce/v1/carts/{$cart->token}?include=items" )
        ->assertOk()
        ->assertJsonPath( 'data.type', 'cart' )
        ->assertJsonPath( 'data.subtotal', [ 'amount' => 2_500, 'currency' => 'EUR' ] )
        ->assertJsonPath( 'data.items.0.line_total', [ 'amount' => 2_500, 'currency' => 'EUR' ] );

    $this->getJson( '/api/ecommerce/v1/carts/' . str_repeat( 'z', 40 ) )->assertNotFound();
} );

it( 'hides product cost outside admin requests', function (): void {
    $product = Product::factory()->create();
    $product->prices()->create( [ 'currency' => 'USD', 'price_amount' => 1_000, 'cost_amount' => 400 ] );

    $price = $this->getJson( "/api/ecommerce/v1/products/{$product->id}?include=prices" )->json( 'data.prices.0' );

    expect( $price['price'] )->toBe( [ 'amount' => 1_000, 'currency' => 'USD' ] );
    expect( $price )->not->toHaveKey( 'cost' );
} );

it( 'registers every route under the ecommerce.api. name prefix', function (): void {
    expect( ProductResource::NAME )->toBe( 'product' );
    expect( app( 'router' )->has( 'ecommerce.api.products.index' ) )->toBeTrue();
} );
