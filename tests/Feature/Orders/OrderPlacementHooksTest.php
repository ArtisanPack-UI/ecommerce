<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Services\OrderPlacementHooks;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'runs order attributes through order.placing with the cart and returns the result', function (): void {
    $cart     = Cart::factory()->create();
    $received = null;

    addFilter( 'ap.ecommerce.order.placing', function ( array $attributes, Cart $source ) use ( &$received ): array {
        $received = [ $attributes, $source->id ];

        return $attributes + [ 'meta' => [ 'channel' => 'pos' ] ];
    } );

    $attributes = app( OrderPlacementHooks::class )->placing( [ 'email' => 'shopper@example.com' ], $cart );

    expect( $received )->toBe( [ [ 'email' => 'shopper@example.com' ], $cart->id ] );
    expect( $attributes )->toBe( [ 'email' => 'shopper@example.com', 'meta' => [ 'channel' => 'pos' ] ] );
} );

it( 'refuses an order.placing return that is not an array', function (): void {
    addFilter( 'ap.ecommerce.order.placing', fn (): ?array => null );

    app( OrderPlacementHooks::class )->placing( [ 'email' => 'shopper@example.com' ], Cart::factory()->create() );
} )->throws( UnexpectedValueException::class );

it( 'fires order.placed with the order', function (): void {
    $order = Order::factory()->create();
    $fired = null;

    addAction( 'ap.ecommerce.order.placed', function ( Order $placed ) use ( &$fired ): void {
        $fired = $placed->id;
    } );

    app( OrderPlacementHooks::class )->placed( $order );

    expect( $fired )->toBe( $order->id );
} );
