<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Catalog\ProductViews;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductChild;
use ArtisanPackUI\Ecommerce\Models\Satellite;
use ArtisanPackUI\Ecommerce\Registries\AccountMenuRegistry;
use ArtisanPackUI\Ecommerce\Services\DigitalDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\ApiUser;

require_once __DIR__ . '/../Api/ApiTestHelpers.php';

uses( RefreshDatabase::class );

afterEach( function (): void {
    removeAllActions( 'ap.ecommerce.product.viewed' );
} );

it( 'lists the core account menu, showing downloads only to customers who have some', function (): void {
    $customer = Customer::factory()->create();
    $keys     = fn (): array => array_column( app( AccountMenuRegistry::class )->visibleTo( $customer ), 'key' );

    expect( $keys() )->toBe( [ 'profile', 'orders', 'addresses', 'notifications' ] );

    $item = OrderItem::factory()->create( [ 'order_id' => Order::factory()->forCustomer( $customer )->create()->id ] );
    app( DigitalDownloadService::class )->issue( $item, DigitalFile::factory()->create() );

    expect( $keys() )->toBe( [ 'profile', 'orders', 'addresses', 'downloads', 'notifications' ] );
} );

it( 'takes satellite entries in position order and respects their visibility and satellite', function (): void {
    $menu = app( AccountMenuRegistry::class );
    $menu->register( 'wishlist', [ 'label' => 'Wishlist', 'route' => 'https://shop.test/wishlist', 'position' => 25 ] );
    $menu->register( 'vip', [ 'label' => 'VIP', 'route' => 'vip', 'visible' => static fn ( ?Customer $customer ): bool => false ] );
    $menu->register( 'subscriptions', [ 'label' => 'Subscriptions', 'route' => 'subs', 'satellite' => 'artisanpack-ui/ecommerce-subscriptions' ] );
    Satellite::factory()->uninstalled()->create( [ 'package_name' => 'artisanpack-ui/ecommerce-subscriptions' ] );

    $entries = collect( $menu->visibleTo( Customer::factory()->create() ) )->keyBy( 'key' );

    expect( $entries->keys()->all() )->toBe( [ 'profile', 'orders', 'wishlist', 'addresses', 'notifications' ] )
        ->and( $entries['wishlist']['url'] )->toBe( 'https://shop.test/wishlist' )
        ->and( $entries['orders']['url'] )->toBeNull();

    expect( fn () => $menu->register( 'wishlist', [ 'label' => 'Again', 'route' => 'x' ] ) )->toThrow( InvalidArgumentException::class )
        ->and( fn () => $menu->register( 'bad', [ 'label' => '', 'route' => 'x' ] ) )->toThrow( InvalidArgumentException::class );
} );

it( 'serves the account menu over REST', function (): void {
    Customer::factory()->create( [ 'email' => 'ada@example.test', 'user_id' => 7 ] );
    Sanctum::actingAs( ApiUser::make( 7, 'ada@example.test' ), [ 'ecommerce:storefront' ] );

    $this->getJson( '/api/ecommerce/v1/me/account-menu' )
        ->assertOk()
        ->assertJsonPath( 'data.0.key', 'profile' )
        ->assertJsonPath( 'data.0.label', 'Account details' );
} );

it( 'describes grouped children as quantity fields and bundle contents as info', function (): void {
    $print = Product::factory()->create( [ 'name' => 'Print' ] );
    $frame = Product::factory()->create( [ 'name' => 'Frame' ] );
    $draft = Product::factory()->create( [ 'name' => 'Hidden', 'status' => 'draft' ] );

    $group  = Product::factory()->create( [ 'type' => 'grouped' ] );
    $bundle = Product::factory()->create( [ 'type' => 'bundled' ] );

    foreach ( [ $print, $frame, $draft ] as $position => $child ) {
        ProductChild::query()->create( [ 'parent_product_id' => $group->id, 'child_product_id' => $child->id, 'quantity' => 1, 'position' => $position ] );
    }

    ProductChild::query()->create( [ 'parent_product_id' => $bundle->id, 'child_product_id' => $print->id, 'quantity' => 2, 'position' => 0 ] );

    $grouped = $group->productType()->storefrontOptions( $group );
    $bundled = $bundle->productType()->storefrontOptions( $bundle );

    expect( array_column( $grouped, 'label' ) )->toBe( [ 'Print', 'Frame' ] )
        ->and( $grouped[0]['type'] )->toBe( 'quantity' )
        ->and( $grouped[0]['meta']['product_id'] )->toBe( $print->id )
        ->and( $bundled[0]['type'] )->toBe( 'info' )
        ->and( $bundled[0]['meta']['items'] )->toBe( [ [ 'product_id' => $print->id, 'variant_id' => null, 'name' => 'Print', 'quantity' => 2 ] ] );

    $this->getJson( "/api/ecommerce/v1/products/{$group->id}/purchase-options" )
        ->assertOk()
        ->assertJsonCount( 2, 'data.options' );
} );

it( 'fires product.viewed with the signed-in customer, over REST too', function (): void {
    $seen = [];
    addAction( 'ap.ecommerce.product.viewed', function ( Product $product, ?Customer $customer ) use ( &$seen ): void {
        $seen[] = [ $product->id, $customer?->id ];
    } );

    $product  = Product::factory()->create();
    $customer = Customer::factory()->create( [ 'email' => 'ada@example.test', 'user_id' => 7 ] );

    ProductViews::record( $product );

    $this->postJson( "/api/ecommerce/v1/products/{$product->id}/views", [], idem() + [ 'Idempotency-Key' => 'view-0123456789abcdef' ] )->assertStatus( 202 );

    Sanctum::actingAs( ApiUser::make( 7, 'ada@example.test' ), [ 'ecommerce:storefront' ] );
    $this->postJson( "/api/ecommerce/v1/products/{$product->id}/views", [], idem() )->assertStatus( 202 );

    expect( $seen )->toBe( [ [ $product->id, null ], [ $product->id, null ], [ $product->id, $customer->id ] ] );

    $this->postJson( '/api/ecommerce/v1/products/999999/views', [], idem() )->assertNotFound();
} );
