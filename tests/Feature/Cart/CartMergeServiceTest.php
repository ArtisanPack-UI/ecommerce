<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\CartMerged;
use ArtisanPackUI\Ecommerce\Exceptions\CartCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Services\CartMergeService;
use ArtisanPackUI\Ecommerce\Services\CartService;
use ArtisanPackUI\Ecommerce\ValueObjects\CartMergeResolution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->cartService  = app( CartService::class );
    $this->mergeService = app( CartMergeService::class );
} );

function makeGuestAndUserCarts( string $guestCurrency = 'USD', string $userCurrency = 'USD' ): array
{
    $guest = Cart::factory()->currency( $guestCurrency )->guest()->create();
    $user  = Cart::factory()->currency( $userCurrency )->create();

    return [ $guest, $user ];
}

function addLine( Cart $cart, Product $product, int $qty, int $unit, string $currency, array $options = [], ?int $variantId = null ): CartItem
{
    return app( CartService::class )->addItem( $cart, [
        'product_id'          => $product->id,
        'product_variant_id'  => $variantId,
        'quantity'            => $qty,
        'unit_price_amount'   => $unit,
        'unit_price_currency' => $currency,
        'options'             => $options,
    ] );
}

it( 'sums quantities on same-variant, keeps separate lines on different variants, and fires CartMerged', function (): void {
    Event::fake( [ CartMerged::class ] );

    [ $guest, $user ] = makeGuestAndUserCarts();

    $productA = Product::factory()->simple()->create();
    $productB = Product::factory()->simple()->create();

    addLine( $user, $productA, 1, 1_000, 'USD', [ 'size' => 'S' ] );
    addLine( $guest, $productA, 2, 1_000, 'USD', [ 'size' => 'S' ] );
    addLine( $guest, $productA, 1, 1_000, 'USD', [ 'size' => 'M' ] );
    addLine( $guest, $productB, 3, 500, 'USD' );

    $result = $this->mergeService->merge( $guest, $user );

    expect( $result->id )->toBe( $user->id );
    expect( CartItem::query()->where( 'cart_id', $result->id )->count() )->toBe( 3 );
    expect( Cart::query()->where( 'id', $guest->id )->exists() )->toBeFalse();

    $mergedSizeS = CartItem::query()
        ->where( 'cart_id', $result->id )
        ->where( 'product_id', $productA->id )
        ->where( 'options_hash', CartItem::hashOptions( [ 'size' => 'S' ] ) )
        ->first();
    expect( $mergedSizeS->quantity )->toBe( 3 );

    Event::assertDispatched(
        CartMerged::class,
        fn ( CartMerged $event ) => $event->result->is( $result ) && 3 === $event->guestItemsMerged,
    );
} );

it( 'rotates the destination cart token after a successful merge', function (): void {
    [ $guest, $user ] = makeGuestAndUserCarts();
    $originalToken    = $user->token;

    $result = $this->mergeService->merge( $guest, $user );

    expect( $result->token )->not->toBe( $originalToken );
    expect( strlen( $result->token ) )->toBe( 40 );
} );

it( 'throws when currencies differ and no resolution is provided', function (): void {
    [ $guest, $user ] = makeGuestAndUserCarts( 'USD', 'EUR' );

    try {
        $this->mergeService->merge( $guest, $user );
        expect( false )->toBeTrue( 'Expected CartCurrencyMismatchException' );
    } catch ( CartCurrencyMismatchException $e ) {
        expect( $e->guestCurrency() )->toBe( 'USD' );
        expect( $e->destinationCurrency() )->toBe( 'EUR' );
    }
} );

it( 'keep-guest-currency rewrites destination currency and drops destination lines', function (): void {
    [ $guest, $user ] = makeGuestAndUserCarts( 'USD', 'EUR' );

    $product = Product::factory()->simple()->create();
    addLine( $user, $product, 5, 500, 'EUR' );
    addLine( $guest, $product, 2, 1_000, 'USD' );

    $result = $this->mergeService->merge( $guest, $user, CartMergeResolution::KeepGuestCurrency );

    expect( $result->currency )->toBe( 'USD' );
    expect( $result->subtotal_currency )->toBe( 'USD' );
    $lines = CartItem::query()->where( 'cart_id', $result->id )->get();
    expect( $lines )->toHaveCount( 1 );
    expect( $lines->first()->quantity )->toBe( 2 );
    expect( $lines->first()->unit_price_currency )->toBe( 'USD' );
} );

it( 'switch-to-account-currency retains the destination cart and discards the guest cart', function (): void {
    [ $guest, $user ] = makeGuestAndUserCarts( 'USD', 'EUR' );

    $product = Product::factory()->simple()->create();
    addLine( $user, $product, 5, 500, 'EUR' );
    addLine( $guest, $product, 2, 1_000, 'USD' );

    Event::fake( [ CartMerged::class ] );

    $result = $this->mergeService->merge( $guest, $user, CartMergeResolution::SwitchToAccountCurrency );

    expect( $result->currency )->toBe( 'EUR' );
    $lines = CartItem::query()->where( 'cart_id', $result->id )->get();
    expect( $lines )->toHaveCount( 1 );
    expect( $lines->first()->quantity )->toBe( 5 );
    expect( Cart::query()->where( 'id', $guest->id )->exists() )->toBeFalse();

    Event::assertDispatched(
        CartMerged::class,
        fn ( CartMerged $event ) => 0 === $event->guestItemsMerged,
    );
} );

it( 'cancel-merge discards the guest cart, returns the destination unchanged, and does not fire CartMerged', function (): void {
    Event::fake( [ CartMerged::class ] );

    [ $guest, $user ] = makeGuestAndUserCarts( 'USD', 'EUR' );
    $originalToken    = $user->token;

    $product = Product::factory()->simple()->create();
    addLine( $user, $product, 1, 500, 'EUR' );
    addLine( $guest, $product, 2, 1_000, 'USD' );

    $mergedHook = 0;
    addAction( 'ap.ecommerce.cart.merged', function () use ( &$mergedHook ): void {
        ++$mergedHook;
    } );

    $result = $this->mergeService->merge( $guest, $user, CartMergeResolution::CancelMerge );

    expect( $result->token )->toBe( $originalToken );
    expect( CartItem::query()->where( 'cart_id', $result->id )->count() )->toBe( 1 );
    expect( Cart::query()->where( 'id', $guest->id )->exists() )->toBeFalse();
    expect( $mergedHook )->toBe( 0 );

    Event::assertNotDispatched( CartMerged::class );
} );

it( 'returns the destination cart untouched when guest and destination are the same row', function (): void {
    $cart = Cart::factory()->create();

    $result = $this->mergeService->merge( $cart, $cart );

    expect( $result->id )->toBe( $cart->id );
} );

it( 'lets the merging filter substitute the result cart', function (): void {
    [ $guest, $user ] = makeGuestAndUserCarts();
    $substitute       = Cart::factory()->create();

    addFilter( 'ap.ecommerce.cart.merging', fn () => $substitute );

    $result = $this->mergeService->merge( $guest, $user );

    expect( $result->id )->toBe( $substitute->id );
} );
