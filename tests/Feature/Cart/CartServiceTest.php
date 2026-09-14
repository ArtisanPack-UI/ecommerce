<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\CartCreated;
use ArtisanPackUI\Ecommerce\Events\CartUpdated;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->service = app( CartService::class );
} );

it( 'creates a cart with a random token, mirroring paired currency columns from the store base currency', function (): void {
    config()->set( 'artisanpack.ecommerce.base_currency', 'EUR' );

    Event::fake( [ CartCreated::class ] );

    $cart = $this->service->create();

    expect( $cart->exists )->toBeTrue();
    expect( strlen( $cart->token ) )->toBe( 40 );
    expect( $cart->currency )->toBe( 'EUR' );
    expect( $cart->subtotal_currency )->toBe( 'EUR' );
    expect( $cart->total_currency )->toBe( 'EUR' );

    Event::assertDispatched( CartCreated::class, fn ( CartCreated $event ) => $event->cart->is( $cart ) );
} );

it( 'lets the creating filter override attributes before persist', function (): void {
    addFilter( 'ap.ecommerce.cart.creating', function ( array $attrs ): array {
        $attrs['email'] = 'seeded@example.com';

        return $attrs;
    } );

    $cart = $this->service->create();

    expect( $cart->email )->toBe( 'seeded@example.com' );
} );

it( 'finds a cart by its opaque token', function (): void {
    $cart = Cart::factory()->create( [ 'token' => 'abc' . str_repeat( 'x', 37 ) ] );

    $result = $this->service->findByToken( $cart->token );

    expect( $result )->not->toBeNull();
    expect( $result->id )->toBe( $cart->id );
    expect( $this->service->findByToken( 'nope' ) )->toBeNull();
} );

it( 'adds a new cart line and fires itemAdded + CartUpdated', function (): void {
    Event::fake( [ CartUpdated::class ] );

    $cart    = Cart::factory()->create();
    $product = Product::factory()->simple()->create();

    $captured = null;
    addAction( 'ap.ecommerce.cart.itemAdded', function ( CartItem $item ) use ( &$captured ): void {
        $captured = $item->id;
    } );

    $item = $this->service->addItem( $cart, [
        'product_id'          => $product->id,
        'quantity'            => 2,
        'unit_price_amount'   => 1_500,
        'unit_price_currency' => 'USD',
    ] );

    expect( $item )->not->toBeNull();
    expect( $item->quantity )->toBe( 2 );
    expect( $item->line_total_amount )->toBe( 3_000 );
    expect( $item->options_hash )->toBe( CartItem::hashOptions( [] ) );
    expect( $captured )->toBe( $item->id );

    Event::assertDispatched( CartUpdated::class );
} );

it( 'sums the quantity on the existing line when a matching (product, variant, options_hash) is re-added', function (): void {
    $cart    = Cart::factory()->create();
    $product = Product::factory()->simple()->create();

    $line = [
        'product_id'          => $product->id,
        'quantity'            => 1,
        'unit_price_amount'   => 1_000,
        'unit_price_currency' => 'USD',
        'options'             => [ 'size' => 'M', 'color' => 'red' ],
    ] ;

    $first  = $this->service->addItem( $cart, $line );
    $second = $this->service->addItem( $cart, array_merge( $line, [ 'quantity' => 3, 'options' => [ 'color' => 'red', 'size' => 'M' ] ] ) );

    expect( $first->id )->toBe( $second->id );
    expect( $second->quantity )->toBe( 4 );
    expect( $second->line_total_amount )->toBe( 4_000 );
    expect( CartItem::query()->where( 'cart_id', $cart->id )->count() )->toBe( 1 );
} );

it( 'keeps lines separate when options differ', function (): void {
    $cart    = Cart::factory()->create();
    $product = Product::factory()->simple()->create();

    $this->service->addItem( $cart, [
        'product_id'          => $product->id,
        'quantity'            => 1,
        'unit_price_amount'   => 1_000,
        'unit_price_currency' => 'USD',
        'options'             => [ 'size' => 'S' ],
    ] );

    $this->service->addItem( $cart, [
        'product_id'          => $product->id,
        'quantity'            => 1,
        'unit_price_amount'   => 1_000,
        'unit_price_currency' => 'USD',
        'options'             => [ 'size' => 'L' ],
    ] );

    expect( CartItem::query()->where( 'cart_id', $cart->id )->count() )->toBe( 2 );
} );

it( 'lets an itemAdding filter veto the add by returning null', function (): void {
    addFilter( 'ap.ecommerce.cart.itemAdding', fn () => null );

    $cart    = Cart::factory()->create();
    $product = Product::factory()->simple()->create();

    $result = $this->service->addItem( $cart, [
        'product_id'          => $product->id,
        'quantity'            => 1,
        'unit_price_amount'   => 1_000,
        'unit_price_currency' => 'USD',
    ] );

    expect( $result )->toBeNull();
    expect( CartItem::query()->count() )->toBe( 0 );
} );

it( 'rejects a line priced in a currency different from the cart', function (): void {
    $cart    = Cart::factory()->currency( 'USD' )->create();
    $product = Product::factory()->simple()->create();

    expect( fn () => $this->service->addItem( $cart, [
        'product_id'          => $product->id,
        'quantity'            => 1,
        'unit_price_amount'   => 1_000,
        'unit_price_currency' => 'EUR',
    ] ) )->toThrow( InvalidArgumentException::class );
} );

it( 'updates the line quantity and recomputes line totals', function (): void {
    $cart    = Cart::factory()->create();
    $product = Product::factory()->simple()->create();
    $item    = $this->service->addItem( $cart, [
        'product_id'          => $product->id,
        'quantity'            => 1,
        'unit_price_amount'   => 500,
        'unit_price_currency' => 'USD',
    ] );

    $updated = $this->service->updateItemQuantity( $cart, $item, 4 );

    expect( $updated )->not->toBeNull();
    expect( $updated->quantity )->toBe( 4 );
    expect( $updated->line_total_amount )->toBe( 2_000 );
} );

it( 'removes the line when quantity is set to zero or below', function (): void {
    $cart    = Cart::factory()->create();
    $product = Product::factory()->simple()->create();
    $item    = $this->service->addItem( $cart, [
        'product_id'          => $product->id,
        'quantity'            => 1,
        'unit_price_amount'   => 500,
        'unit_price_currency' => 'USD',
    ] );

    $result = $this->service->updateItemQuantity( $cart, $item, 0 );

    expect( $result )->toBeNull();
    expect( CartItem::query()->count() )->toBe( 0 );
} );

it( 'rotates the token to a fresh value on demand', function (): void {
    $cart     = Cart::factory()->create();
    $original = $cart->token;

    $rotated = $this->service->rotateToken( $cart );

    expect( $rotated->id )->toBe( $cart->id );
    expect( $rotated->token )->not->toBe( $original );
    expect( strlen( $rotated->token ) )->toBe( 40 );
} );
