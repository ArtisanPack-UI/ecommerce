<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\CartMerged;
use ArtisanPackUI\Ecommerce\Exceptions\CartCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
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

it( 'keep-guest-currency switches the account cart to the guest currency and re-prices every line from the catalog', function (): void {
    [ $guest, $user ] = makeGuestAndUserCarts( 'USD', 'EUR' );

    $shared  = Product::factory()->simple()->create();
    $mugs    = Product::factory()->simple()->create();
    $euOnly  = Product::factory()->simple()->create();
    ProductPrice::factory()->forPriceable( $shared )->create( [ 'currency' => 'USD', 'price_amount' => 1_000 ] );
    ProductPrice::factory()->forPriceable( $shared )->create( [ 'currency' => 'EUR', 'price_amount' => 900 ] );
    ProductPrice::factory()->forPriceable( $mugs )->create( [ 'currency' => 'USD', 'price_amount' => 400 ] );
    ProductPrice::factory()->forPriceable( $mugs )->create( [ 'currency' => 'EUR', 'price_amount' => 350 ] );
    ProductPrice::factory()->forPriceable( $euOnly )->create( [ 'currency' => 'EUR', 'price_amount' => 2_000 ] );

    addLine( $user, $shared, 1, 900, 'EUR' );
    addLine( $user, $mugs, 2, 350, 'EUR' );
    addLine( $user, $euOnly, 1, 2_000, 'EUR' );
    addLine( $guest, $shared, 2, 1_000, 'USD' );

    $result = $this->mergeService->merge( $guest, $user, CartMergeResolution::KeepGuestCurrency );
    $lines  = CartItem::query()->where( 'cart_id', $result->id )->get()->keyBy( 'product_id' );

    expect( $result->currency )->toBe( 'USD' )
        ->and( $result->subtotal_currency )->toBe( 'USD' )
        ->and( $lines )->toHaveCount( 2 )
        ->and( $lines[ $shared->id ]->quantity )->toBe( 3 )
        ->and( $lines[ $shared->id ]->unit_price_amount )->toBe( 1_000 )
        ->and( $lines[ $mugs->id ]->unit_price_currency )->toBe( 'USD' )
        ->and( $lines[ $mugs->id ]->unit_price_amount )->toBe( 400 )
        ->and( $result->subtotal_amount )->toBe( 3 * 1_000 + 2 * 400 );
} );

it( 'switch-to-account-currency keeps the account currency and carries the guest lines re-priced in it', function (): void {
    [ $guest, $user ] = makeGuestAndUserCarts( 'USD', 'EUR' );

    $shared = Product::factory()->simple()->create();
    $usOnly = Product::factory()->simple()->create();
    ProductPrice::factory()->forPriceable( $shared )->create( [ 'currency' => 'USD', 'price_amount' => 1_000 ] );
    ProductPrice::factory()->forPriceable( $shared )->create( [ 'currency' => 'EUR', 'price_amount' => 900 ] );
    ProductPrice::factory()->forPriceable( $usOnly )->create( [ 'currency' => 'USD', 'price_amount' => 700 ] );

    addLine( $user, $shared, 5, 900, 'EUR' );
    addLine( $guest, $shared, 2, 1_000, 'USD' );
    addLine( $guest, $usOnly, 1, 700, 'USD' );

    Event::fake( [ CartMerged::class ] );

    $result = $this->mergeService->merge( $guest, $user, CartMergeResolution::SwitchToAccountCurrency );
    $lines  = CartItem::query()->where( 'cart_id', $result->id )->get();

    expect( $result->currency )->toBe( 'EUR' )
        ->and( $lines )->toHaveCount( 1 )
        ->and( $lines->first()->quantity )->toBe( 7 )
        ->and( $lines->first()->unit_price_amount )->toBe( 900 )
        ->and( $result->subtotal_amount )->toBe( 7 * 900 )
        ->and( Cart::query()->where( 'id', $guest->id )->exists() )->toBeFalse();

    // The US-only line had no EUR price, so only the shared line counts as carried.
    Event::assertDispatched( CartMerged::class, fn ( CartMerged $event ) => 1 === $event->guestItemsMerged );
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
