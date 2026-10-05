<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition as PromotionConditionContract;
use ArtisanPackUI\Ecommerce\Exceptions\PromotionUsageLimitReachedException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\PromotionAction;
use ArtisanPackUI\Ecommerce\Models\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\PromotionUsage;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Services\PromotionEngine;
use ArtisanPackUI\Ecommerce\ValueObjects\PromotionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->engine = app( PromotionEngine::class );
} );

/**
 * Creates a promotion with conditions / actions given as `type => config`
 * pairs (lists, so a type can repeat).
 *
 * @param  array<string, mixed>                      $attributes
 * @param  array<int, array{0: string, 1: array}>    $conditions
 * @param  array<int, array{0: string, 1: array}>    $actions
 */
function promotionWith( array $attributes, array $conditions, array $actions, ?string $couponCode = null ): Promotion
{
    $promotion = Promotion::factory()->create( $attributes + [ 'source_type' => null === $couponCode ? 'automatic' : 'coupon' ] );

    foreach ( $conditions as [ $type, $config ] ) {
        PromotionCondition::factory()->create( [ 'promotion_id' => $promotion->id, 'type' => $type, 'config' => $config ] );
    }

    foreach ( $actions as [ $type, $config ] ) {
        PromotionAction::factory()->create( [ 'promotion_id' => $promotion->id, 'type' => $type, 'config' => $config ] );
    }

    if ( null !== $couponCode ) {
        Coupon::factory()->create( [ 'promotion_id' => $promotion->id, 'code' => $couponCode ] );
    }

    return $promotion;
}

it( 'applies WELCOME10 for 10% off a first order and rejects it for a returning customer', function (): void {
    promotionWith( [ 'key' => 'welcome' ], [ [ 'customer-first-order', [] ] ], [ [ 'percent-off-cart', [ 'percent' => 10 ] ] ], 'WELCOME10' );

    $newCustomer = Customer::factory()->create();
    $returning   = Customer::factory()->create();
    Order::factory()->forCustomer( $returning )->create();

    $first = $this->engine->evaluate( cartWithLines( [ [ 'unit' => 5_000 ] ], 'USD', [ 'customer_id' => $newCustomer->id ] ), 'welcome10' );
    $again = $this->engine->evaluate( cartWithLines( [ [ 'unit' => 5_000 ] ], 'USD', [ 'customer_id' => $returning->id ] ), 'WELCOME10' );

    expect( $first->couponStatus )->toBe( PromotionResult::COUPON_APPLIED );
    expect( $first->couponCode )->toBe( 'WELCOME10' );
    expect( (int) $first->discountTotal()->getAmount() )->toBe( 500 );

    expect( $again->couponStatus )->toBe( PromotionResult::COUPON_NOT_ELIGIBLE );
    expect( $again->discountTotal()->isZero() )->toBeTrue();
} );

it( 'grants automatic free shipping over $75', function ( int $unit, bool $free ): void {
    promotionWith( [], [ [ 'min-subtotal', [ 'amount' => 7_500 ] ] ], [ [ 'free-shipping', [] ] ] );

    $result = $this->engine->evaluate( cartWithLines( [ [ 'unit' => $unit ] ] ) );

    expect( $result->hasFreeShipping() )->toBe( $free );
    expect( $result->couponStatus )->toBeNull();
} )->with( [
    'under' => [ 7_499, false ],
    'over'  => [ 7_500, true ],
] );

it( 'reports invalid, inactive, and exhausted coupons', function (): void {
    promotionWith( [ 'is_active' => false ], [], [ [ 'percent-off-cart', [ 'percent' => 5 ] ] ], 'OFF' );
    promotionWith( [ 'ends_at' => Carbon::now()->subDay() ], [], [ [ 'percent-off-cart', [ 'percent' => 5 ] ] ], 'EXPIRED' );
    promotionWith( [ 'starts_at' => Carbon::now()->addDay() ], [], [ [ 'percent-off-cart', [ 'percent' => 5 ] ] ], 'SOON' );
    $used = promotionWith( [ 'usage_limit_total' => 1 ], [], [ [ 'percent-off-cart', [ 'percent' => 5 ] ] ], 'USEDUP' );
    $used->forceFill( [ 'times_used' => 1 ] )->save();

    $cart = cartWithLines( [ [] ] );

    expect( $this->engine->evaluate( $cart, 'NOPE' )->couponStatus )->toBe( PromotionResult::COUPON_INVALID );
    expect( $this->engine->evaluate( $cart, 'OFF' )->couponStatus )->toBe( PromotionResult::COUPON_INACTIVE );
    expect( $this->engine->evaluate( $cart, 'EXPIRED' )->couponStatus )->toBe( PromotionResult::COUPON_INACTIVE );
    expect( $this->engine->evaluate( $cart, 'SOON' )->couponStatus )->toBe( PromotionResult::COUPON_INACTIVE );
    expect( $this->engine->evaluate( $cart, 'USEDUP' )->couponStatus )->toBe( PromotionResult::COUPON_EXHAUSTED );
    expect( $this->engine->evaluate( $cart, '   ' )->couponCode )->toBeNull();
} );

it( 'enforces the per-customer usage limit', function (): void {
    $promotion = promotionWith( [ 'usage_limit_per_customer' => 1 ], [], [ [ 'percent-off-cart', [ 'percent' => 5 ] ] ], 'ONCE' );
    $customer  = Customer::factory()->create();

    PromotionUsage::factory()->create( [ 'promotion_id' => $promotion->id, 'customer_id' => $customer->id ] );

    $mine  = $this->engine->evaluate( cartWithLines( [ [] ], 'USD', [ 'customer_id' => $customer->id ] ), 'ONCE' );
    $guest = $this->engine->evaluate( cartWithLines( [ [] ] ), 'ONCE' );

    expect( $mine->couponStatus )->toBe( PromotionResult::COUPON_EXHAUSTED );
    expect( $guest->couponStatus )->toBe( PromotionResult::COUPON_APPLIED );
} );

it( 'stacks non-exclusive promotions in priority order against the remaining subtotal', function (): void {
    $second = promotionWith( [ 'priority' => 20 ], [], [ [ 'percent-off-cart', [ 'percent' => 10 ] ] ] );
    $first  = promotionWith( [ 'priority' => 10 ], [], [ [ 'fixed-off-cart', [ 'amount' => 1_000 ] ] ] );

    $result = $this->engine->evaluate( cartWithLines( [ [ 'unit' => 10_000 ] ] ) );

    expect( array_map( fn ( $p ) => $p->id, $result->applied ) )->toBe( [ $first->id, $second->id ] );
    // 1000 off, then 10% of the remaining 9000.
    expect( (int) $result->ledger->amountForPromotion( $second->id )->getAmount() )->toBe( 900 );
    expect( (int) $result->discountTotal()->getAmount() )->toBe( 1_900 );
} );

it( 'lets a higher-priority exclusive promotion block everything below it', function (): void {
    $exclusive = promotionWith( [ 'priority' => 1, 'is_exclusive' => true ], [], [ [ 'percent-off-cart', [ 'percent' => 20 ] ] ] );
    promotionWith( [ 'priority' => 5 ], [], [ [ 'percent-off-cart', [ 'percent' => 10 ] ] ], 'EXTRA' );

    $result = $this->engine->evaluate( cartWithLines( [ [ 'unit' => 10_000 ] ] ), 'EXTRA' );

    expect( array_map( fn ( $p ) => $p->id, $result->applied ) )->toBe( [ $exclusive->id ] );
    expect( $result->couponStatus )->toBe( PromotionResult::COUPON_NOT_COMBINABLE );
    expect( (int) $result->discountTotal()->getAmount() )->toBe( 2_000 );
} );

it( 'skips a lower-priority exclusive promotion once anything has applied', function (): void {
    $stackable = promotionWith( [ 'priority' => 1 ], [], [ [ 'fixed-off-cart', [ 'amount' => 500 ] ] ] );
    promotionWith( [ 'priority' => 9, 'is_exclusive' => true ], [], [ [ 'percent-off-cart', [ 'percent' => 50 ] ] ], 'HALF' );

    $result = $this->engine->evaluate( cartWithLines( [ [ 'unit' => 10_000 ] ] ), 'HALF' );

    expect( array_map( fn ( $p ) => $p->id, $result->applied ) )->toBe( [ $stackable->id ] );
    expect( $result->couponStatus )->toBe( PromotionResult::COUPON_NOT_COMBINABLE );
} );

it( 'never discounts past the cart subtotal however promotions stack', function (): void {
    promotionWith( [ 'priority' => 1 ], [], [ [ 'fixed-off-cart', [ 'amount' => 3_000 ] ] ] );
    promotionWith( [ 'priority' => 2 ], [], [ [ 'fixed-off-cart', [ 'amount' => 3_000 ] ] ] );

    $result = $this->engine->evaluate( cartWithLines( [ [ 'unit' => 2_500 ], [ 'unit' => 1_500 ] ] ) );

    expect( (int) $result->discountTotal()->getAmount() )->toBe( 4_000 );
    expect( array_map( fn ( $m ) => (int) $m->getAmount(), array_values( $result->ledger->lineDiscounts() ) ) )->toBe( [ 2_500, 1_500 ] );
} );

it( 'fails closed on unknown or throwing conditions and skips unknown actions', function (): void {
    app( PromotionConditionRegistry::class )->register( 'explodes', new class implements PromotionConditionContract {
        public function key(): string
        {
            return 'explodes';
        }

        public function label(): string
        {
            return 'Explodes';
        }

        public function evaluate( Cart $cart, array $config ): bool
        {
            throw new RuntimeException( 'boom' );
        }
    } );

    promotionWith( [], [ [ 'not-registered', [] ] ], [ [ 'percent-off-cart', [ 'percent' => 50 ] ] ] );
    promotionWith( [], [ [ 'explodes', [] ] ], [ [ 'percent-off-cart', [ 'percent' => 50 ] ] ] );
    $ok = promotionWith( [], [], [ [ 'not-an-action', [] ], [ 'fixed-off-cart', [ 'amount' => 100 ] ] ] );

    $result = $this->engine->evaluate( cartWithLines( [ [ 'unit' => 1_000 ] ] ) );

    expect( array_map( fn ( $p ) => $p->id, $result->applied ) )->toBe( [ $ok->id ] );
    expect( (int) $result->discountTotal()->getAmount() )->toBe( 100 );
} );

it( 'accepts satellite conditions such as customer-lifetime-value-over', function (): void {
    app( PromotionConditionRegistry::class )->register( 'crm:customer-lifetime-value-over', new class implements PromotionConditionContract {
        public function key(): string
        {
            return 'crm:customer-lifetime-value-over';
        }

        public function label(): string
        {
            return 'Lifetime value over';
        }

        public function evaluate( Cart $cart, array $config ): bool
        {
            return (int) ( $cart->customer?->total_spent_amount ?? 0 ) > (int) ( $config['amount'] ?? PHP_INT_MAX );
        }
    } );

    promotionWith( [], [ [ 'crm:customer-lifetime-value-over', [ 'amount' => 100_000 ] ] ], [ [ 'percent-off-cart', [ 'percent' => 15 ] ] ] );

    $vip    = Customer::factory()->create( [ 'total_spent_amount' => 250_000, 'total_spent_currency' => 'USD' ] );
    $result = $this->engine->evaluate( cartWithLines( [ [ 'unit' => 10_000 ] ], 'USD', [ 'customer_id' => $vip->id ] ) );

    expect( (int) $result->discountTotal()->getAmount() )->toBe( 1_500 );
} );

it( 'lets satellites inject candidate promotions and fires discountApplied per promotion', function (): void {
    $giftCard = promotionWith( [ 'source_type' => 'gift-card' ], [], [ [ 'fixed-off-cart', [ 'amount' => 2_500 ] ] ] );

    addFilter( 'ap.ecommerce.promotions.candidates', fn ( array $candidates ) => [ ...$candidates, $giftCard->fresh() ] );

    $applied = [];
    addAction( 'ap.ecommerce.pricing.discountApplied', function ( Promotion $promotion, Cart $cart, $amount ) use ( &$applied ): void {
        $applied[ $promotion->id ] = (int) $amount->getAmount();
    } );

    $this->engine->evaluate( cartWithLines( [ [ 'unit' => 10_000 ] ] ) );

    expect( $applied )->toBe( [ $giftCard->id => 2_500 ] );
} );

it( 'records usage on placement and refuses the last use twice', function (): void {
    $promotion = promotionWith( [ 'usage_limit_total' => 1 ], [], [ [ 'fixed-off-cart', [ 'amount' => 300 ] ] ] );
    $customer  = Customer::factory()->create();
    $cart      = cartWithLines( [ [ 'unit' => 1_000 ] ], 'USD', [ 'customer_id' => $customer->id ] );

    $first  = $this->engine->evaluate( $cart );
    $second = $this->engine->evaluate( $cart );

    $usages = $this->engine->recordUsage( Order::factory()->forCustomer( $customer )->create(), $first );

    expect( $usages )->toHaveCount( 1 );
    expect( $usages[0]->amount_discounted )->toBe( 300 );
    expect( $usages[0]->customer_id )->toBe( $customer->id );
    expect( $promotion->fresh()->times_used )->toBe( 1 );

    expect( fn () => $this->engine->recordUsage( Order::factory()->create(), $second ) )
        ->toThrow( PromotionUsageLimitReachedException::class );
    expect( PromotionUsage::query()->count() )->toBe( 1 );
} );

it( 'writes the discount total onto the cart', function (): void {
    promotionWith( [], [], [ [ 'fixed-off-cart', [ 'amount' => 250 ] ] ] );

    $cart = cartWithLines( [ [ 'unit' => 1_000 ] ] );
    $this->engine->applyToCart( $cart, $this->engine->evaluate( $cart ) );

    expect( $cart->fresh()->discount_amount )->toBe( 250 );
} );
