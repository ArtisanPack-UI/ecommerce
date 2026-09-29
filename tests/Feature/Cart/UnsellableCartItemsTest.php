<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'lists lines whose product type went missing after they were added and flags them in cart meta', function (): void {
    $orphan = Product::factory()->create( [ 'type' => 'subscription' ] );
    $cart   = cartWithLines( [
        [ 'unit' => 1_000, 'qty' => 1 ],
        [ 'unit' => 2_000, 'qty' => 1, 'product' => $orphan ],
    ] );

    $service    = app( StorefrontCartService::class );
    $orphanLine = $cart->items->firstWhere( 'product_id', $orphan->id );

    expect( $service->unsellableItems( $cart )->modelKeys() )->toBe( [ $orphanLine->id ] );

    $service->refreshTotals( $cart );

    expect( $cart->fresh()->meta[ StorefrontCartService::UNSELLABLE_META_KEY ] )->toBe( [ $orphanLine->id ] )
        ->and( $cart->fresh()->subtotal_amount )->toBe( 3_000 );
} );

it( 'clears the flag once no unsellable lines remain', function (): void {
    $orphan = Product::factory()->create( [ 'type' => 'subscription' ] );
    $cart   = cartWithLines( [ [ 'unit' => 1_000, 'qty' => 1, 'product' => $orphan ] ] );

    $service = app( StorefrontCartService::class );
    $service->refreshTotals( $cart );

    expect( $cart->fresh()->meta )->toHaveKey( StorefrontCartService::UNSELLABLE_META_KEY );

    $cart->items()->delete();
    $service->refreshTotals( $cart->fresh() );

    expect( (array) $cart->fresh()->meta )->not->toHaveKey( StorefrontCartService::UNSELLABLE_META_KEY );
} );

it( 'reports nothing for a cart of sellable products', function (): void {
    $cart = cartWithLines( [ [ 'unit' => 1_000, 'qty' => 2 ] ] );

    expect( app( StorefrontCartService::class )->unsellableItems( $cart ) )->toBeEmpty();
} );
