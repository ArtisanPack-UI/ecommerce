<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\PromotionUsageLimitReachedException;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\PromotionAction;
use ArtisanPackUI\Ecommerce\Models\PromotionUsage;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Services\PromotionEngine;
use ArtisanPackUI\Ecommerce\Support\DiscountLedger;
use ArtisanPackUI\Ecommerce\ValueObjects\PromotionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

function reviewPromotion( array $attributes, string $action, array $config ): Promotion
{
    $promotion = Promotion::factory()->create( $attributes );
    PromotionAction::factory()->create( [ 'promotion_id' => $promotion->id, 'type' => $action, 'config' => $config ] );

    return $promotion->fresh();
}

it( 'does not let a promotion that grants nothing block others or claim a coupon', function (): void {
    $absent = Product::factory()->create();

    reviewPromotion( [ 'priority' => 0, 'is_exclusive' => true ], 'percent-off-product', [ 'percent' => 50, 'product_ids' => [ $absent->id ] ] );
    $sitewide = reviewPromotion( [ 'priority' => 10 ], 'percent-off-cart', [ 'percent' => 10 ] );

    $result = app( PromotionEngine::class )->evaluate( cartWithLines( [ [ 'unit' => 10_000 ] ] ) );

    expect( array_map( fn ( $p ) => $p->id, $result->applied ) )->toBe( [ $sitewide->id ] );
    expect( (int) $result->discountTotal()->getAmount() )->toBe( 1_000 );
} );

it( 'reports a zero-value coupon as not eligible', function (): void {
    $absent    = Product::factory()->create();
    $promotion = reviewPromotion( [ 'source_type' => 'coupon' ], 'percent-off-product', [ 'percent' => 50, 'product_ids' => [ $absent->id ] ] );
    $promotion->coupons()->create( [ 'code' => 'NOTHING' ] );

    expect( app( PromotionEngine::class )->evaluate( cartWithLines( [ [] ] ), 'NOTHING' )->couponStatus )
        ->toBe( PromotionResult::COUPON_NOT_ELIGIBLE );
} );

it( 'drops unsaved and inactive promotions handed over through the candidates filter', function (): void {
    $inactive = reviewPromotion( [ 'is_active' => false, 'source_type' => 'gift-card' ], 'fixed-off-cart', [ 'amount' => 500 ] );

    addFilter( 'ap.ecommerce.promotions.candidates', fn ( array $c ) => [ ...$c, $inactive, new Promotion( [ 'key' => 'ghost', 'name' => 'Ghost' ] ) ] );

    $result = app( PromotionEngine::class )->evaluate( cartWithLines( [ [ 'unit' => 1_000 ] ] ) );

    expect( $result->applied )->toBe( [] );
} );

it( 'records usage idempotently per order', function (): void {
    $promotion = reviewPromotion( [], 'fixed-off-cart', [ 'amount' => 200 ] );
    $engine    = app( PromotionEngine::class );
    $order     = Order::factory()->create();
    $result    = $engine->evaluate( cartWithLines( [ [ 'unit' => 1_000 ] ] ) );

    $engine->recordUsage( $order, $result );
    $engine->recordUsage( $order, $result );

    expect( PromotionUsage::query()->count() )->toBe( 1 );
    expect( $promotion->fresh()->times_used )->toBe( 1 );
} );

it( 'enforces the per-customer limit at placement even when the cart was evaluated as a guest', function (): void {
    $promotion = reviewPromotion( [ 'usage_limit_per_customer' => 1 ], 'fixed-off-cart', [ 'amount' => 200 ] );
    $customer  = Customer::factory()->create();
    $engine    = app( PromotionEngine::class );

    PromotionUsage::factory()->create( [ 'promotion_id' => $promotion->id, 'customer_id' => $customer->id ] );

    $guestResult = $engine->evaluate( cartWithLines( [ [ 'unit' => 1_000 ] ] ) );

    expect( $guestResult->applied )->toHaveCount( 1 );
    expect( fn () => $engine->recordUsage( Order::factory()->forCustomer( $customer )->create(), $guestResult ) )
        ->toThrow( PromotionUsageLimitReachedException::class );
} );

it( 'allocates buy-x-get-y across partly overlapping buy and get sets', function (): void {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    $c = Product::factory()->create();

    // Buy {A,B} 2, get {B,C} 1: A×4 funds two applications, rewarded from C.
    $cart   = cartWithLines( [ [ 'product' => $a, 'unit' => 1_000, 'qty' => 4 ], [ 'product' => $c, 'unit' => 300, 'qty' => 2 ], [ 'product' => $b, 'unit' => 800, 'qty' => 1 ] ] );
    $ledger = new DiscountLedger( $cart );

    app( PromotionActionRegistry::class )->get( 'buy-x-get-y' )->apply( $cart, $ledger, [
        'buy_product_ids' => [ $a->id, $b->id ],
        'get_product_ids' => [ $b->id, $c->id ],
        'buy_quantity'    => 2,
        'get_quantity'    => 1,
    ] );

    // Two applications (A,A→C twice); B has no partner left. Both C units free.
    expect( (int) $ledger->total()->getAmount() )->toBe( 600 );
} );
