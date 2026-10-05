<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\CartCompleted;
use ArtisanPackUI\Ecommerce\Events\CouponRedeemed;
use ArtisanPackUI\Ecommerce\Events\OrderPlaced;
use ArtisanPackUI\Ecommerce\Events\PromotionApplied;
use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\PromotionAction;
use ArtisanPackUI\Ecommerce\Models\TaxRate;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Services\OrderPlacementService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/CheckoutTestHelpers.php';

uses( RefreshDatabase::class );

/**
 * A coupon promotion taking `$percent` off the cart, usable `$limit` times.
 */
function placementCoupon( string $code, int $percent = 10, ?int $limit = null, ?int $perCustomer = null ): Promotion
{
    $promotion = Promotion::factory()->create( [ 'source_type' => 'coupon', 'usage_limit_total' => $limit, 'usage_limit_per_customer' => $perCustomer ] );
    PromotionAction::factory()->create( [ 'promotion_id' => $promotion->id, 'type' => 'percent-off-cart', 'config' => [ 'percent' => $percent ] ] );
    Coupon::factory()->create( [ 'promotion_id' => $promotion->id, 'code' => $code ] );

    return $promotion;
}

function placementError( Closure $call, string $code ): void
{
    expect( $call )->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( $code ) );
}

beforeEach( function (): void {
    checkoutGateway();
    checkoutZone();

    $this->carts     = app( StorefrontCartService::class );
    $this->placement = app( OrderPlacementService::class );
    $this->product   = checkoutProduct( [ 'USD' => 2_000 ], [ 'name' => 'Field Notes', 'sku' => 'FN-1' ] );
} );

it( 'places an order with tax, an FX snapshot, coupon usage, held stock and the placement events (D1)', function (): void {
    Event::fake( [ OrderPlaced::class, CartCompleted::class, CouponRedeemed::class, PromotionApplied::class ] );
    TaxRate::factory()->create( [ 'rate_ubps' => 100_000_000 ] );
    InventoryItem::factory()->create( [ 'stockable_type' => $this->product->getMorphClass(), 'stockable_id' => $this->product->id, 'quantity_on_hand' => 5 ] );
    $promotion = placementCoupon( 'ONCE', 10, 1 );

    $cart = readyCart( $this->product, 2 );
    $this->carts->applyCoupon( $cart, 'ONCE' );

    $order = $this->placement->place( $cart, [ 'customer_note' => 'Leave at the door' ] );

    expect( $order->tax_amount )->toBeGreaterThan( 0 )
        ->and( $order->tax_amount )->toBe( 360 )
        ->and( $order->discount_amount )->toBe( 400 )
        ->and( $order->total_amount )->toBe( 4_000 - 400 + 500 + 360 )
        ->and( $order->fx_rate_to_base_e8 )->toBe( 100_000_000 )
        ->and( $order->base_currency )->toBe( 'USD' )
        ->and( $order->system_status )->toBe( 'pending' )
        ->and( $order->payment_status )->toBe( 'pending' )
        ->and( $order->is_claimed )->toBeFalse()
        ->and( $order->customer_note )->toBe( 'Leave at the door' )
        ->and( $order->meta['coupon_code'] )->toBe( 'ONCE' )
        ->and( $order->meta['tax_breakdown'][0]['amount'] )->toBe( 360 )
        ->and( $promotion->refresh()->times_used )->toBe( 1 );

    $reservation = InventoryReservation::query()->sole();

    expect( $reservation->reservable_id )->toBe( $order->id )
        ->and( $reservation->expires_at )->toBeNull()
        ->and( InventoryItem::query()->sole()->availableQuantity() )->toBe( 3 );

    expect( $cart->refresh()->completed_order_id )->toBe( $order->id )
        ->and( $cart->items()->count() )->toBe( 0 );

    Event::assertDispatched( OrderPlaced::class, fn ( OrderPlaced $event ) => $event->order->is( $order ) );
    Event::assertDispatched( CartCompleted::class, fn ( CartCompleted $event ) => $event->cart->id === $cart->id && 1 === $event->cart->items->count() );
    Event::assertDispatched( PromotionApplied::class, fn ( PromotionApplied $event ) => 400 === (int) $event->amount->getAmount() );
    Event::assertDispatched( CouponRedeemed::class, fn ( CouponRedeemed $event ) => 'ONCE' === $event->coupon->code && $event->order->is( $order ) );
} );

it( 'snapshots each line with its own discount and tax and splits shipping across the lines', function (): void {
    TaxRate::factory()->create( [ 'rate_ubps' => 100_000_000 ] );
    $other = checkoutProduct( [ 'USD' => 1_000 ] );
    $cart  = readyCart( $this->product );
    $this->carts->addItem( $cart, $other->id, null, 1 );

    $order = $this->placement->place( $cart );
    $items = $order->items->keyBy( 'product_id' );
    $line  = $items[ $this->product->id ];

    expect( $line->product_snapshot['name'] )->toBe( 'Field Notes' )
        ->and( $line->product_snapshot['sku'] )->toBe( 'FN-1' )
        ->and( $line->product_snapshot['type'] )->toBe( 'simple' )
        ->and( $line->tax_amount )->toBe( 200 )
        ->and( $items->sum( 'shipping_amount' ) )->toBe( 500 )
        ->and( $items->sum( 'tax_amount' ) )->toBe( $order->tax_amount )
        ->and( $items->sum( 'total_amount' ) )->toBe( $order->total_amount );
} );

it( 'marks digital lines for delivery in their snapshot', function (): void {
    $cart  = readyCart( checkoutProduct( [ 'USD' => 900 ], [ 'type' => 'digital' ] ) );
    $order = $this->placement->place( $cart );

    expect( $order->items->sole()->product_snapshot['digital_delivery']['expected'] )->toBeTrue();
} );

it( 'records the shopper\'s language on the order and on a customer without one (H1)', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'ada@example.test', 'locale' => null ] );
    $cart     = readyCart( $this->product, 1, app( StorefrontCartService::class )->create( 'USD', null, $customer, 'de' ) );

    $order = $this->placement->place( $cart );

    expect( $order->locale )->toBe( 'de' )
        ->and( $order->meta )->not->toHaveKey( 'locale' )
        ->and( $customer->refresh()->locale )->toBe( 'de' );

    // An existing preference is never overwritten.
    $customer->forceFill( [ 'locale' => 'fr' ] )->save();
    $this->placement->place( readyCart( $this->product, 1, app( StorefrontCartService::class )->create( 'USD', null, $customer, 'es' ) ) );

    expect( $customer->refresh()->locale )->toBe( 'fr' );
} );

it( 'runs the order number through the order.number filter', function (): void {
    addFilter( 'ap.ecommerce.order.number', fn ( string $number ): string => 'SHOP-' . $number );

    expect( $this->placement->place( readyCart( $this->product ) )->order_number )->toStartWith( 'SHOP-' );
} );

it( 'rolls everything back when a step fails', function (): void {
    InventoryItem::factory()->create( [ 'stockable_type' => $this->product->getMorphClass(), 'stockable_id' => $this->product->id, 'quantity_on_hand' => 5 ] );
    $promotion = placementCoupon( 'SAVE', 10, 5 );
    $cart      = readyCart( $this->product, 2 );
    $this->carts->applyCoupon( $cart, 'SAVE' );

    addAction( 'ap.ecommerce.inventory.reserved', function (): void {
        throw new RuntimeException( 'boom' );
    } );

    expect( fn () => $this->placement->place( $cart ) )->toThrow( RuntimeException::class, 'boom' );

    expect( Order::query()->count() )->toBe( 0 )
        ->and( $cart->refresh()->completed_order_id )->toBeNull()
        ->and( $cart->items()->sole()->quantity )->toBe( 2 )
        ->and( $promotion->refresh()->times_used )->toBe( 0 )
        ->and( InventoryReservation::query()->where( 'reservable_type', ( new Order() )->getMorphClass() )->count() )->toBe( 0 )
        // The cart keeps the hold checkout gave it.
        ->and( (int) InventoryReservation::query()->where( 'reservable_id', $cart->id )->where( 'reservable_type', $cart->getMorphClass() )->sum( 'quantity' ) )->toBe( 2 );
} );

it( 'refuses a cart that already became an order, so two placements give one order', function (): void {
    $cart = readyCart( $this->product );

    $this->placement->place( $cart );

    placementError( fn () => $this->placement->place( $cart ), 'cart-closed' );
    expect( Order::query()->count() )->toBe( 1 );
} );

it( 'refuses a second use of a single-use coupon instead of charging full price', function (): void {
    placementCoupon( 'ONCE', 10, 1 );

    $first = readyCart( $this->product );
    $this->carts->applyCoupon( $first, 'ONCE' );
    $second = readyCart( $this->product );
    $this->carts->applyCoupon( $second, 'ONCE' );

    $this->placement->place( $first );

    placementError( fn () => $this->placement->place( $second ), 'coupon-no-longer-valid' );
} );

it( 'holds guests to a per-customer limit by their email (D12)', function (): void {
    placementCoupon( 'WELCOME', 10, null, 1 );

    $first = readyCart( $this->product );
    $this->carts->applyCoupon( $first, 'WELCOME' );
    $this->placement->place( $first );

    $again = readyCart( $this->product );

    expect( fn () => $this->carts->applyCoupon( $again, 'WELCOME' ) )
        ->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( 'coupon-invalid' ) );
} );

it( 'refuses an incomplete cart', function ( Closure $break, string $code ): void {
    $cart = readyCart( $this->product );
    $break( $cart );

    placementError( fn () => $this->placement->place( $cart ), $code );
    expect( Order::query()->count() )->toBe( 0 );
} )->with( [
    'empty'            => [ fn ( Cart $cart ) => $cart->items()->delete(), 'cart-empty' ],
    'no email'         => [ fn ( Cart $cart ) => $cart->forceFill( [ 'email' => null ] )->save(), 'email-required' ],
    'no address'       => [ fn ( Cart $cart ) => $cart->forceFill( [ 'shipping_address' => null, 'billing_address' => null ] )->save(), 'shipping-address-required' ],
    'no shipping rate' => [ fn ( Cart $cart ) => $cart->forceFill( [ 'meta' => [] ] )->save(), 'shipping-rate-required' ],
    'unsellable line'  => [ fn ( Cart $cart ) => Product::query()->whereKey( $cart->items()->sole()->product_id )->update( [ 'type' => 'subscription' ] ), 'items-unavailable' ],
] );

it( 'refuses when the total differs from what the shopper is paying', function (): void {
    $cart = readyCart( $this->product );

    placementError( fn () => $this->placement->place( $cart, [ OrderPlacementService::EXPECTED_TOTAL => 1 ] ), 'totals-changed' );
} );

it( 'refuses a variable product line without a variant', function (): void {
    $cart     = readyCart( $this->product );
    $variable = checkoutProduct( [ 'USD' => 1_000 ], [ 'type' => 'variable' ] );
    CartItem::factory()->create( [ 'cart_id' => $cart->id, 'product_id' => $variable->id, 'product_variant_id' => null, 'unit_price_amount' => 1_000, 'quantity' => 1 ] );

    placementError( fn () => $this->placement->place( $cart ), 'variant-required' );
} );

it( 'refuses when stock ran out since the cart was filled', function (): void {
    $item = InventoryItem::factory()->create( [ 'stockable_type' => $this->product->getMorphClass(), 'stockable_id' => $this->product->id, 'quantity_on_hand' => 5 ] );
    $cart = readyCart( $this->product, 3 );
    $item->forceFill( [ 'quantity_on_hand' => 2 ] )->save();

    placementError( fn () => $this->placement->place( $cart ), 'insufficient-stock' );
} );

it( 'snapshots the exchange rate for an order in another currency', function (): void {
    config()->set( 'artisanpack.ecommerce.currency.enabled', [ 'EUR' ] );
    config()->set( 'artisanpack.ecommerce.currency.rates', [ 'EUR' => [ 'USD' => 110_000_000 ] ] );
    $product = checkoutProduct( [ 'USD' => 2_000, 'EUR' => 1_800 ] );
    $cart    = app( StorefrontCartService::class )->create( 'EUR' );
    ArtisanPackUI\Ecommerce\Models\ShippingMethod::query()->update( [ 'config' => [ 'amount' => [ 'USD' => 500, 'EUR' => 450 ] ] ] );

    $order = $this->placement->place( readyCart( $product, 1, $cart ) );

    expect( $order->currency )->toBe( 'EUR' )
        ->and( $order->base_currency )->toBe( 'USD' )
        ->and( $order->fx_rate_to_base_e8 )->toBe( 110_000_000 );
} );

it( 'queues an order.placed webhook', function (): void {
    Queue::fake();
    WebhookSubscription::factory()->create( [ 'events' => [ 'order.placed' ] ] );

    $this->placement->place( readyCart( $this->product ) );

    expect( WebhookDelivery::query()->where( 'event', 'order.placed' )->count() )->toBe( 1 );
} );
