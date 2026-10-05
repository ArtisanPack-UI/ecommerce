<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\Support\RateLimitPolicyRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->product = Product::factory()->create();
    $this->price   = ProductPrice::factory()->forPriceable( $this->product )->create( [ 'currency' => 'USD', 'price_amount' => 1_000 ] );
    $this->cart    = Cart::factory()->create( [ 'currency' => 'USD' ] );
} );

it( 'rejects changes to completed or expired carts', function ( array $state ): void {
    $this->cart->forceFill( $state )->save();

    $this->postJson( "/api/ecommerce/v1/carts/{$this->cart->token}/items", [ 'product_id' => $this->product->id, 'quantity' => 1 ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.code', 'cart-closed' );

    expect( CartItem::query()->count() )->toBe( 0 );
} )->with( [
    'converted to an order' => [ [ 'completed_order_id' => 99 ] ],
    'expired'               => [ [ 'expires_at' => Carbon::now()->subMinute() ] ],
] );

it( 'caps the units on one line across repeated adds', function (): void {
    $service = app( StorefrontCartService::class );
    $service->addItem( $this->cart, $this->product->id, null, StorefrontCartService::MAX_LINE_QUANTITY );

    $this->postJson( "/api/ecommerce/v1/carts/{$this->cart->token}/items", [ 'product_id' => $this->product->id, 'quantity' => 1 ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.code', 'quantity-limit' );

    expect( CartItem::query()->sole()->quantity )->toBe( StorefrontCartService::MAX_LINE_QUANTITY );
} );

it( 're-prices a line when its quantity changes, so an ended sale price does not stick', function (): void {
    $service = app( StorefrontCartService::class );
    $item    = $service->addItem( $this->cart, $this->product->id, null, 1 );

    expect( $item->unit_price_amount )->toBe( 1_000 );

    $this->price->forceFill( [ 'price_amount' => 1_500 ] )->save();

    $service->addItem( $this->cart, $this->product->id, null, 1 );

    expect( $item->refresh()->unit_price_amount )->toBe( 1_500 )
        ->and( $item->line_subtotal_amount )->toBe( 3_000 )
        ->and( $this->cart->refresh()->subtotal_amount )->toBe( 3_000 );

    $this->price->forceFill( [ 'price_amount' => 2_000 ] )->save();
    $service->updateItem( $this->cart, $item->refresh(), 3 );

    expect( $item->refresh()->unit_price_amount )->toBe( 2_000 )
        ->and( $this->cart->refresh()->total_amount )->toBe( 6_000 );
} );

it( 're-prices every line on any change, not just the one touched', function (): void {
    $service = app( StorefrontCartService::class );
    $other   = Product::factory()->create();
    ProductPrice::factory()->forPriceable( $other )->create( [ 'currency' => 'USD', 'price_amount' => 500 ] );

    $onSale = $service->addItem( $this->cart, $this->product->id, null, 2 );
    $this->price->forceFill( [ 'price_amount' => 1_800 ] )->save();

    $service->addItem( $this->cart, $other->id, null, 1 );

    expect( $onSale->refresh()->unit_price_amount )->toBe( 1_800 )
        ->and( $this->cart->refresh()->subtotal_amount )->toBe( 2 * 1_800 + 500 );
} );

it( 'keeps admin-only relations out of non-admin renders even when loaded', function (): void {
    $cart = $this->cart->load( 'customer' );
    $cart->customer()->associate( ArtisanPackUI\Ecommerce\Models\Customer::factory()->create() )->save();
    $cart->load( 'customer' );

    $public = ( new ArtisanPackUI\Ecommerce\Http\Resources\CartResource( $cart ) )->resolve( Illuminate\Http\Request::create( '/' ) );

    $admin = Illuminate\Http\Request::create( '/' );
    $admin->attributes->set( ArtisanPackUI\Ecommerce\Http\Middleware\EnsureEcommerceAbility::ADMIN_ATTRIBUTE, true );

    expect( $public )->not->toHaveKey( 'customer' )
        ->and( ( new ArtisanPackUI\Ecommerce\Http\Resources\CartResource( $cart ) )->resolve( $admin ) )->toHaveKey( 'customer' );
} );

it( 'caps how deep REST search can page', function (): void {
    $this->getJson( '/api/ecommerce/v1/search?q=x&per_page=100&page=101' )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'page' );
} );

it( 'keys the cart rate limit to the cart in the URL, not a client header', function (): void {
    config()->set( 'artisanpack.ecommerce.rate_limits.cart.mutate.per_cart', 1 );
    RateLimitPolicyRegistrar::register();

    $url = "/api/ecommerce/v1/carts/{$this->cart->token}";

    $this->withHeader( 'X-Cart-Token', 'spoof-1' )->getJson( $url )->assertOk();
    $this->withHeader( 'X-Cart-Token', 'spoof-2' )->getJson( $url )->assertStatus( 429 );
} );

it( 'answers an array search term with a 422, not a 500', function (): void {
    $this->getJson( '/api/ecommerce/v1/search?q[]=x' )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'q' );
} );
