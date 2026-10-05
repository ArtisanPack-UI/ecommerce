<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\PromotionUsage;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionSourceRegistry;
use ArtisanPackUI\Ecommerce\Support\DiscountLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Money\Currency;
use Money\Money;

uses( RefreshDatabase::class );

function condition( string $key ): ArtisanPackUI\Ecommerce\Contracts\PromotionCondition
{
    return app( PromotionConditionRegistry::class )->get( $key );
}

/**
 * Applies an action to a fresh ledger and returns it.
 */
function applyAction( string $key, ArtisanPackUI\Ecommerce\Models\Cart $cart, array $config ): DiscountLedger
{
    $ledger = new DiscountLedger( $cart );
    app( PromotionActionRegistry::class )->get( $key )->apply( $cart, $ledger, $config );

    return $ledger;
}

/**
 * @return array<int, int>
 */
function lineDiscounts( DiscountLedger $ledger ): array
{
    return array_values( array_map( fn ( Money $m ) => (int) $m->getAmount(), $ledger->lineDiscounts() ) );
}

it( 'registers the core sources, conditions, and actions', function (): void {
    expect( app( PromotionSourceRegistry::class )->keys() )->toBe( [ 'automatic', 'coupon' ] );
    expect( app( PromotionConditionRegistry::class )->keys() )->toBe( [
        'min-subtotal', 'cart-contains-product', 'cart-contains-product-type', 'customer-in-group', 'day-of-week', 'customer-first-order',
    ] );
    expect( app( PromotionActionRegistry::class )->keys() )->toBe( [
        'percent-off-cart', 'fixed-off-cart', 'percent-off-product', 'free-shipping', 'buy-x-get-y', 'add-free-item', 'tiered-discount',
    ] );
} );

it( 'evaluates min-subtotal against the cart currency', function (): void {
    config()->set( 'artisanpack.ecommerce.currency.rates', [ 'USD' => [ 'EUR' => 50_000_000 ] ] );

    $cart = cartWithLines( [ [ 'unit' => 5_000 ] ], 'EUR' );

    expect( condition( 'min-subtotal' )->evaluate( $cart, [ 'amount' => 10_000 ] ) )->toBeTrue();
    expect( condition( 'min-subtotal' )->evaluate( $cart, [ 'amount' => [ 'EUR' => 6_000 ] ] ) )->toBeFalse();
    expect( condition( 'min-subtotal' )->evaluate( $cart, [] ) )->toBeFalse();
} );

it( 'evaluates cart-contains-product in any and all modes', function (): void {
    $a = Product::factory()->create();
    $b = Product::factory()->create();

    $cart = cartWithLines( [ [ 'product' => $a, 'qty' => 2 ] ] );

    expect( condition( 'cart-contains-product' )->evaluate( $cart, [ 'product_ids' => [ $a->id, $b->id ] ] ) )->toBeTrue();
    expect( condition( 'cart-contains-product' )->evaluate( $cart, [ 'product_ids' => [ $a->id ], 'min_quantity' => 3 ] ) )->toBeFalse();
    expect( condition( 'cart-contains-product' )->evaluate( $cart, [ 'product_ids' => [ $a->id, $b->id ], 'match' => 'all' ] ) )->toBeFalse();
    expect( condition( 'cart-contains-product' )->evaluate( $cart, [] ) )->toBeFalse();
} );

it( 'evaluates customer-in-group from customer meta and the groups filter', function (): void {
    $wholesale = Customer::factory()->create( [ 'meta' => [ 'groups' => [ 'wholesale' ] ] ] );
    $retail    = Customer::factory()->create();

    expect( condition( 'customer-in-group' )->evaluate( cartWithLines( [ [] ], 'USD', [ 'customer_id' => $wholesale->id ] ), [ 'groups' => [ 'wholesale' ] ] ) )->toBeTrue();
    expect( condition( 'customer-in-group' )->evaluate( cartWithLines( [ [] ], 'USD', [ 'customer_id' => $retail->id ] ), [ 'groups' => [ 'wholesale' ] ] ) )->toBeFalse();
    expect( condition( 'customer-in-group' )->evaluate( cartWithLines( [ [] ] ), [ 'groups' => [ 'wholesale' ] ] ) )->toBeFalse();

    addFilter( 'ap.ecommerce.customer.groups', fn ( array $groups ) => [ ...$groups, 'vip' ] );

    expect( condition( 'customer-in-group' )->evaluate( cartWithLines( [ [] ], 'USD', [ 'customer_id' => $retail->id ] ), [ 'groups' => [ 'vip' ] ] ) )->toBeTrue();
} );

it( 'evaluates day-of-week in the configured timezone', function (): void {
    Carbon::setTestNow( Carbon::parse( '2026-10-02 02:00:00', 'UTC' ) ); // Fri in UTC, Thu in Chicago.

    $cart = cartWithLines( [ [] ] );

    expect( condition( 'day-of-week' )->evaluate( $cart, [ 'days' => [ 5 ], 'timezone' => 'UTC' ] ) )->toBeTrue();
    expect( condition( 'day-of-week' )->evaluate( $cart, [ 'days' => [ 5 ], 'timezone' => 'America/Chicago' ] ) )->toBeFalse();
    expect( condition( 'day-of-week' )->evaluate( $cart, [ 'days' => [ 5 ], 'timezone' => 'Not/AZone' ] ) )->toBeFalse();

    Carbon::setTestNow();
} );

it( 'evaluates customer-first-order by customer, then by email, ignoring failed orders', function (): void {
    $customer = Customer::factory()->create();
    Order::factory()->withSystemStatus( 'failed' )->create( [ 'customer_id' => $customer->id ] );
    Order::factory()->guest()->create( [ 'email' => 'Returning@Example.com' ] );

    expect( condition( 'customer-first-order' )->evaluate( cartWithLines( [ [] ], 'USD', [ 'customer_id' => $customer->id ] ), [] ) )->toBeTrue();
    expect( condition( 'customer-first-order' )->evaluate( cartWithLines( [ [] ], 'USD', [ 'email' => 'returning@example.com' ] ), [] ) )->toBeFalse();
    // D12: an anonymous cart (no customer, no email) can't prove it's a first order.
    expect( condition( 'customer-first-order' )->evaluate( cartWithLines( [ [] ], 'USD', [ 'email' => null, 'customer_id' => null ] ), [] ) )->toBeFalse();
    expect( condition( 'customer-first-order' )->evaluate( cartWithLines( [ [] ], 'USD', [ 'email' => 'new@example.com' ] ), [] ) )->toBeTrue();
} );

it( 'holds guests to per-customer limits by the email on their orders (D12)', function (): void {
    $promotion = Promotion::factory()->create( [ 'usage_limit_per_customer' => 1 ] );
    $order     = Order::factory()->guest()->create( [ 'email' => 'ada@example.com' ] );
    PromotionUsage::query()->create( [ 'promotion_id' => $promotion->id, 'order_id' => $order->id, 'customer_id' => null, 'amount_discounted' => 100, 'currency' => 'USD' ] );

    expect( $promotion->hasUsageRemaining( null, 'ADA@example.com' ) )->toBeFalse()
        ->and( $promotion->hasUsageRemaining( null, 'eve@example.com' ) )->toBeTrue()
        ->and( $promotion->hasUsageRemaining() )->toBeTrue();
} );

it( 'takes percent-off-cart spread proportionally across lines', function (): void {
    $ledger = applyAction( 'percent-off-cart', cartWithLines( [ [ 'unit' => 3_000 ], [ 'unit' => 1_000 ] ] ), [ 'percent' => '12.5' ] );

    expect( (int) $ledger->total()->getAmount() )->toBe( 500 );
    expect( lineDiscounts( $ledger ) )->toBe( [ 375, 125 ] );
} );

it( 'ignores malformed percentages and amounts', function ( string $key, array $config ): void {
    expect( applyAction( $key, cartWithLines( [ [ 'unit' => 1_000 ] ] ), $config )->total()->isZero() )->toBeTrue();
} )->with( [
    'percent > 100'   => [ 'percent-off-cart', [ 'percent' => 150 ] ],
    'percent missing' => [ 'percent-off-cart', [] ],
    'percent e-notn'  => [ 'percent-off-cart', [ 'percent' => '1e1' ] ],
    'amount negative' => [ 'fixed-off-cart', [ 'amount' => -100 ] ],
    'bogus tiers'     => [ 'tiered-discount', [ 'tiers' => 'nope' ] ],
    'bxgy no qty'     => [ 'buy-x-get-y', [ 'buy_product_ids' => [ 1 ] ] ],
    'free item no id' => [ 'add-free-item', [] ],
] );

it( 'takes percent-off-product only from matching lines', function (): void {
    $target = Product::factory()->create();
    $ledger = applyAction( 'percent-off-product', cartWithLines( [ [ 'unit' => 2_000, 'product' => $target ], [ 'unit' => 2_000 ] ] ), [
        'percent'     => 25,
        'product_ids' => [ $target->id ],
    ] );

    expect( lineDiscounts( $ledger ) )->toBe( [ 500, 0 ] );
} );

it( 'discounts the cheapest reward units in buy-x-get-y', function (): void {
    $shirt = Product::factory()->create();
    $socks = Product::factory()->create();

    // Buy 2 shirts, get 1 pair of socks free; 5 shirts → 2 applications.
    $cart   = cartWithLines( [ [ 'unit' => 2_000, 'qty' => 5, 'product' => $shirt ], [ 'unit' => 500, 'qty' => 3, 'product' => $socks ] ] );
    $ledger = applyAction( 'buy-x-get-y', $cart, [
        'buy_product_ids' => [ $shirt->id ],
        'buy_quantity'    => 2,
        'get_product_ids' => [ $socks->id ],
        'get_quantity'    => 1,
    ] );

    expect( lineDiscounts( $ledger ) )->toBe( [ 0, 1_000 ] );
} );

it( 'handles buy-x-get-y over a shared set with a percentage and application cap', function (): void {
    $mug = Product::factory()->create();

    // Buy 2 get 1 at 50% off; 7 units → 2 applications (each consumes 3).
    $cart   = cartWithLines( [ [ 'unit' => 1_000, 'qty' => 7, 'product' => $mug ] ] );
    $ledger = applyAction( 'buy-x-get-y', $cart, [
        'buy_product_ids' => [ $mug->id ],
        'buy_quantity'    => 2,
        'get_quantity'    => 1,
        'percent'         => 50,
    ] );

    expect( (int) $ledger->total()->getAmount() )->toBe( 1_000 );

    $capped = applyAction( 'buy-x-get-y', $cart, [
        'buy_product_ids'  => [ $mug->id ],
        'buy_quantity'     => 2,
        'get_quantity'     => 1,
        'max_applications' => 1,
    ] );

    expect( (int) $capped->total()->getAmount() )->toBe( 1_000 );
} );

it( 'discounts in-cart units for add-free-item and records the shortfall', function (): void {
    $gift = Product::factory()->create();

    $inCart = applyAction( 'add-free-item', cartWithLines( [ [ 'unit' => 800, 'qty' => 1, 'product' => $gift ] ] ), [ 'product_id' => $gift->id, 'quantity' => 2 ] );

    expect( (int) $inCart->total()->getAmount() )->toBe( 800 );
    expect( $inCart->freeItems() )->toBe( [ [ 'product_id' => $gift->id, 'variant_id' => null, 'quantity' => 1, 'promotion_id' => null ] ] );
} );

it( 'picks the highest qualifying tier for tiered-discount', function ( int $unit, int $expected ): void {
    $ledger = applyAction( 'tiered-discount', cartWithLines( [ [ 'unit' => $unit ] ] ), [ 'tiers' => [
        [ 'min_subtotal' => 5_000, 'percent' => 5 ],
        [ 'min_subtotal' => 10_000, 'percent' => 10 ],
        [ 'min_subtotal' => 20_000, 'amount' => 3_000 ],
    ] ] );

    expect( (int) $ledger->total()->getAmount() )->toBe( $expected );
} )->with( [
    'no tier'   => [ 4_999, 0 ],
    '5%'        => [ 6_000, 300 ],
    '10%'       => [ 10_000, 1_000 ],
    'fixed top' => [ 25_000, 3_000 ],
] );

it( 'flags free shipping', function (): void {
    expect( applyAction( 'free-shipping', cartWithLines( [ [] ] ), [] )->hasFreeShipping() )->toBeTrue();
} );

it( 'rejects ledger writes in another currency', function (): void {
    ( new DiscountLedger( cartWithLines( [ [] ] ) ) )->discountCart( new Money( 100, new Currency( 'EUR' ) ) );
} )->throws( InvalidArgumentException::class );
