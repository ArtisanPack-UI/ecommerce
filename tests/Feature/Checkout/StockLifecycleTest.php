<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductChild;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use ArtisanPackUI\Ecommerce\Services\RefundService;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/CheckoutTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->gateway = checkoutGateway();
    checkoutZone();
} );

/**
 * Checks out `$product` × `$quantity`, stopping at a 3DS step-up (the order
 * is placed, its payment not yet captured).
 */
function placeUnpaid( $test, Product $product, int $quantity ): array
{
    $cart    = readyCart( $product, $quantity );
    $session = app( CheckoutService::class )->createPaymentSession( $cart );
    $test->gateway->confirm( $session->reference, PaymentSession::STATUS_REQUIRES_ACTION );

    return [ $cart, $session, app( CheckoutService::class )->finalize( $cart, $session->reference )->order ];
}

it( 'holds an order\'s stock past the checkout TTL, takes it off the shelf at capture, and puts it back on a restocking refund (D4)', function (): void {
    $product = checkoutProduct();
    $row     = InventoryItem::factory()->create( [ 'stockable_type' => $product->getMorphClass(), 'stockable_id' => $product->id, 'quantity_on_hand' => 10 ] );

    [ $cart, $session, $order ] = placeUnpaid( $this, $product, 3 );

    Carbon::setTestNow( Carbon::now()->addMinutes( 20 ) );
    app( InventoryService::class )->releaseExpired();
    Carbon::setTestNow();

    expect( InventoryReservation::query()->sum( 'quantity' ) )->toBe( 3 )
        ->and( $row->refresh()->quantity_on_hand )->toBe( 10 );

    $this->gateway->confirm( $session->reference );
    app( CheckoutService::class )->finalize( $cart, $session->reference );

    expect( $row->refresh()->quantity_on_hand )->toBe( 7 )
        ->and( $row->quantity_reserved )->toBe( 0 );

    $line = $order->refresh()->items->sole();
    app( RefundService::class )->issue( $order, [ [ 'order_item_id' => $line->id, 'quantity' => 3, 'amount' => (int) $line->total_amount, 'restock' => true ] ] );

    expect( $row->refresh()->quantity_on_hand )->toBe( 10 );
} );

it( 'takes a bundle\'s members off the shelf and restocks them', function (): void {
    $bundle = Product::factory()->bundled()->create();
    ProductPrice::factory()->forPriceable( $bundle )->create( [ 'currency' => 'USD', 'price_amount' => 5_000 ] );
    $mug = Product::factory()->create();
    $row = InventoryItem::factory()->create( [ 'stockable_type' => $mug->getMorphClass(), 'stockable_id' => $mug->id, 'quantity_on_hand' => 10 ] );
    ProductChild::factory()->create( [ 'parent_product_id' => $bundle->id, 'child_product_id' => $mug->id, 'quantity' => 2 ] );

    [ $cart, $session, $order ] = placeUnpaid( $this, $bundle, 2 );
    $this->gateway->confirm( $session->reference );
    app( CheckoutService::class )->finalize( $cart, $session->reference );

    expect( $row->refresh()->quantity_on_hand )->toBe( 6 );

    $line = $order->refresh()->items->sole();
    app( RefundService::class )->issue( $order, [ [ 'order_item_id' => $line->id, 'quantity' => 1, 'amount' => 100, 'restock' => true ] ] );

    expect( $row->refresh()->quantity_on_hand )->toBe( 8 );
} );
