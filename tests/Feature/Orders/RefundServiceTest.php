<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Events\OrderRefunded;
use ArtisanPackUI\Ecommerce\Events\PaymentRefunded;
use ArtisanPackUI\Ecommerce\Exceptions\RefundNotAllowedException;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\RefundItem;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Services\RefundService;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Money\Money;

uses( RefreshDatabase::class );

/**
 * In-memory test double for {@see PaymentGateway}. Records every refund call
 * and returns whatever result the test configures.
 */
final class RefundServiceTestFakeGateway implements PaymentGateway
{
    /** @var array<int, array{order_id:int, amount:int, currency:string, reason:?string}> */
    public array $calls = [];

    public function __construct(
        public bool $supportsPartial = true,
        public bool $supports = true,
        public ?RefundResult $nextResult = null,
        public string $keyName = 'fake',
    ) {
    }

    public function key(): string
    {
        return $this->keyName;
    }

    public function supportsRefunds(): bool
    {
        return $this->supports;
    }

    public function supportsPartialRefunds(): bool
    {
        return $this->supportsPartial;
    }

    public function refund( Order $order, Money $amount, ?string $reason = null ): RefundResult
    {
        $this->calls[] = [
            'order_id' => $order->id,
            'amount'   => (int) $amount->getAmount(),
            'currency' => $amount->getCurrency()->getCode(),
            'reason'   => $reason,
        ];

        return $this->nextResult ?? RefundResult::success( $amount, 're_fake_123' );
    }
}

beforeEach( function (): void {
    $this->gateway = new RefundServiceTestFakeGateway();
    /** @var PaymentGatewayRegistry $registry */
    $registry = app( PaymentGatewayRegistry::class );
    $registry->register( 'fake', $this->gateway );

    $this->service = app( RefundService::class );
} );

/**
 * @param  array<int, array{qty:int, unit:int, product?:Product}>  $lines
 */
function makeRefundableOrder( array $lines = [ [ 'qty' => 3, 'unit' => 1_000 ] ] ): Order
{
    $order = Order::factory()->create( [
        'payment_status'      => 'paid',
        'payment_gateway_key' => 'fake',
        'subtotal_amount'     => 0,
        'total_amount'        => 0,
    ] );

    foreach ( $lines as $line ) {
        $qty  = (int) ( $line['qty'] ?? 1 );
        $unit = (int) ( $line['unit'] ?? 1_000 );

        $attrs = [
            'order_id'          => $order->id,
            'quantity'          => $qty,
            'unit_price_amount' => $unit,
            'total_amount'      => $qty * $unit,
        ];

        if ( isset( $line['product'] ) ) {
            $attrs['product_id'] = $line['product']->id;
        }

        OrderItem::factory()->create( $attrs );
    }

    $order->refresh();
    $order->subtotal_amount = $order->items->sum( fn ( $i ) => $i->unit_price_amount * $i->quantity );
    $order->total_amount    = $order->subtotal_amount;
    $order->save();

    return $order->fresh( 'items' );
}

it( 'issues a full refund, writes the ledger, updates payment_status, and dispatches both events', function (): void {
    Event::fake( [ OrderRefunded::class, PaymentRefunded::class ] );

    $order = makeRefundableOrder( [ [ 'qty' => 2, 'unit' => 1_500 ] ] );
    $item  = $order->items->first();

    $refund = $this->service->issue(
        $order,
        [
            [ 'order_item_id' => $item->id, 'quantity' => 2, 'amount' => 3_000, 'restock' => false ],
        ],
        actorUserId: 42,
        reason: 'Customer changed their mind.',
    );

    expect( $refund )->toBeInstanceOf( Refund::class );
    expect( $refund->amount )->toBe( 3_000 );
    expect( $refund->currency )->toBe( 'USD' );
    expect( $refund->gateway_reference )->toBe( 're_fake_123' );
    expect( $refund->issued_by_user_id )->toBe( 42 );
    expect( $refund->items )->toHaveCount( 1 );
    expect( $refund->items->first()->quantity )->toBe( 2 );
    expect( $refund->items->first()->restock )->toBeFalse();

    $fresh = $order->fresh();
    expect( $fresh->total_refunded_amount )->toBe( 3_000 );
    expect( $fresh->total_refunded_currency )->toBe( 'USD' );
    expect( $fresh->payment_status )->toBe( 'refunded' );

    expect( $this->gateway->calls )->toHaveCount( 1 );
    expect( $this->gateway->calls[0]['amount'] )->toBe( 3_000 );
    expect( $this->gateway->calls[0]['reason'] )->toBe( 'Customer changed their mind.' );

    $timeline = OrderTimelineEntry::query()->where( 'order_id', $order->id )->sole();
    expect( $timeline->event_type )->toBe( 'order.refunded' );
    expect( $timeline->payload['refund_id'] )->toBe( $refund->id );
    expect( $timeline->payload['payment_status'] )->toBe( 'refunded' );

    Event::assertDispatched(
        OrderRefunded::class,
        fn ( OrderRefunded $e ) => $e->order->id === $order->id && $e->refund->id === $refund->id,
    );
    Event::assertDispatched( PaymentRefunded::class );
} );

it( 'sets payment_status to partially_refunded when only part of the total is refunded', function (): void {
    $order = makeRefundableOrder( [ [ 'qty' => 3, 'unit' => 1_000 ] ] );
    $item  = $order->items->first();

    $this->service->issue(
        $order,
        [ [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 1_000 ] ],
    );

    expect( $order->fresh()->payment_status )->toBe( 'partially_refunded' );
    expect( $order->fresh()->total_refunded_amount )->toBe( 1_000 );
} );

it( 'restocks inventory when restock=true and leaves it alone when restock=false', function (): void {
    $product   = Product::factory()->create();
    $order     = makeRefundableOrder( [ [ 'qty' => 2, 'unit' => 1_000, 'product' => $product ] ] );
    $item      = $order->items->first();
    $inventory = InventoryItem::factory()->create( [
        'stockable_type'   => Product::class,
        'stockable_id'     => $product->id,
        'quantity_on_hand' => 5,
    ] );

    $this->service->issue(
        $order,
        [ [ 'order_item_id' => $item->id, 'quantity' => 2, 'amount' => 2_000, 'restock' => true ] ],
    );

    expect( $inventory->fresh()->quantity_on_hand )->toBe( 7 );
} );

it( 'does not restock when restock flag is omitted or false', function (): void {
    $product   = Product::factory()->create();
    $order     = makeRefundableOrder( [ [ 'qty' => 2, 'unit' => 1_000, 'product' => $product ] ] );
    $item      = $order->items->first();
    $inventory = InventoryItem::factory()->create( [
        'stockable_type'   => Product::class,
        'stockable_id'     => $product->id,
        'quantity_on_hand' => 5,
    ] );

    $this->service->issue(
        $order,
        [ [ 'order_item_id' => $item->id, 'quantity' => 2, 'amount' => 2_000 ] ],
    );

    expect( $inventory->fresh()->quantity_on_hand )->toBe( 5 );
} );

it( 'rejects a refund when the order payment_status is not paid or partially_refunded', function (): void {
    $order = Order::factory()->create( [
        'payment_status'      => 'pending',
        'payment_gateway_key' => 'fake',
    ] );
    $item = OrderItem::factory()->create( [ 'order_id' => $order->id ] );

    expect( fn () => $this->service->issue(
        $order->fresh( 'items' ),
        [ [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 100 ] ],
    ) )->toThrow( RefundNotAllowedException::class );
} );

it( 'rejects a refund whose total exceeds the outstanding refundable balance', function (): void {
    $order = makeRefundableOrder( [ [ 'qty' => 1, 'unit' => 1_000 ] ] );
    $item  = $order->items->first();

    expect( fn () => $this->service->issue(
        $order,
        [ [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 2_000 ] ],
    ) )->toThrow( RefundNotAllowedException::class );
} );

it( 'rejects a partial refund when the active gateway does not support partial refunds', function (): void {
    $this->gateway->supportsPartial = false;

    $order = makeRefundableOrder( [ [ 'qty' => 2, 'unit' => 1_000 ] ] );
    $item  = $order->items->first();

    expect( fn () => $this->service->issue(
        $order,
        [ [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 1_000 ] ],
    ) )->toThrow( RefundNotAllowedException::class );
} );

it( 'allows a full refund through a gateway that only supports full refunds', function (): void {
    $this->gateway->supportsPartial = false;

    $order = makeRefundableOrder( [ [ 'qty' => 2, 'unit' => 1_000 ] ] );
    $item  = $order->items->first();

    $refund = $this->service->issue(
        $order,
        [ [ 'order_item_id' => $item->id, 'quantity' => 2, 'amount' => 2_000 ] ],
    );

    expect( $refund->amount )->toBe( 2_000 );
} );

it( 'rejects a refund whose quantity exceeds what remains unrefunded on the line', function (): void {
    $order = makeRefundableOrder( [ [ 'qty' => 3, 'unit' => 1_000 ] ] );
    $item  = $order->items->first();

    $this->service->issue(
        $order,
        [ [ 'order_item_id' => $item->id, 'quantity' => 2, 'amount' => 2_000 ] ],
    );

    expect( fn () => $this->service->issue(
        $order->fresh( 'items' ),
        [ [ 'order_item_id' => $item->id, 'quantity' => 2, 'amount' => 2_000 ] ],
    ) )->toThrow( RefundNotAllowedException::class );
} );

it( 'does not persist anything when the gateway declines the refund', function (): void {
    Event::fake( [ OrderRefunded::class, PaymentRefunded::class ] );

    $this->gateway->nextResult = RefundResult::failure(
        new Money( '1000', new \Money\Currency( 'USD' ) ),
        'card_declined',
        'The card issuer refused the refund.',
    );

    $order = makeRefundableOrder( [ [ 'qty' => 1, 'unit' => 1_000 ] ] );
    $item  = $order->items->first();

    expect( fn () => $this->service->issue(
        $order,
        [ [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 1_000 ] ],
    ) )->toThrow( RefundNotAllowedException::class );

    expect( Refund::query()->count() )->toBe( 0 );
    expect( RefundItem::query()->count() )->toBe( 0 );
    expect( $order->fresh()->total_refunded_amount )->toBe( 0 );
    expect( $order->fresh()->payment_status )->toBe( 'paid' );

    Event::assertNotDispatched( OrderRefunded::class );
    Event::assertNotDispatched( PaymentRefunded::class );
} );

it( 'rejects a refund when the order has no payment_gateway_key', function (): void {
    $order = Order::factory()->create( [
        'payment_status'      => 'paid',
        'payment_gateway_key' => null,
    ] );
    $item = OrderItem::factory()->create( [ 'order_id' => $order->id ] );

    expect( fn () => $this->service->issue(
        $order->fresh( 'items' ),
        [ [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 100 ] ],
    ) )->toThrow( RefundNotAllowedException::class );
} );

it( 'rejects a refund line for an order item that belongs to a different order', function (): void {
    $order      = makeRefundableOrder();
    $otherOrder = makeRefundableOrder();
    $otherItem  = $otherOrder->items->first();

    expect( fn () => $this->service->issue(
        $order,
        [ [ 'order_item_id' => $otherItem->id, 'quantity' => 1, 'amount' => 1_000 ] ],
    ) )->toThrow( RefundNotAllowedException::class );
} );

it( 'rejects an empty refund payload', function (): void {
    $order = makeRefundableOrder();

    expect( fn () => $this->service->issue( $order, [] ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'rejects a gateway success whose result amount does not match the requested amount', function (): void {
    $order = makeRefundableOrder( [ [ 'qty' => 2, 'unit' => 1_000 ] ] );
    $item  = $order->items->first();

    // Gateway reports success but for a different amount than requested — a partial fulfilment.
    $this->gateway->nextResult = RefundResult::success(
        new Money( '1500', new \Money\Currency( 'USD' ) ),
        're_partial',
    );

    expect( fn () => $this->service->issue(
        $order,
        [ [ 'order_item_id' => $item->id, 'quantity' => 2, 'amount' => 2_000 ] ],
    ) )->toThrow( RefundNotAllowedException::class );

    expect( Refund::query()->count() )->toBe( 0 );
    expect( $order->fresh()->total_refunded_amount )->toBe( 0 );
} );

it( 'rejects a filter that returns a different currency than the order', function (): void {
    $order = makeRefundableOrder( [ [ 'qty' => 1, 'unit' => 1_000 ] ] );
    $item  = $order->items->first();

    addFilter(
        'ap.ecommerce.payment.refunding',
        fn ( Money $amount ) => new Money( $amount->getAmount(), new \Money\Currency( 'EUR' ) ),
    );

    try {
        expect( fn () => $this->service->issue(
            $order,
            [ [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 1_000 ] ],
        ) )->toThrow( RefundNotAllowedException::class );

        expect( Refund::query()->count() )->toBe( 0 );
    } finally {
        removeAllFilters( 'ap.ecommerce.payment.refunding' );
    }
} );

it( 'rejects a filter that pushes the amount over the outstanding balance', function (): void {
    $order = makeRefundableOrder( [ [ 'qty' => 1, 'unit' => 1_000 ] ] );
    $item  = $order->items->first();

    addFilter(
        'ap.ecommerce.payment.refunding',
        fn () => new Money( '9999', new \Money\Currency( 'USD' ) ),
    );

    try {
        expect( fn () => $this->service->issue(
            $order,
            [ [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 1_000 ] ],
        ) )->toThrow( RefundNotAllowedException::class );

        expect( Refund::query()->count() )->toBe( 0 );
    } finally {
        removeAllFilters( 'ap.ecommerce.payment.refunding' );
    }
} );

it( 'refuses to resolve a gateway whose own key does not match its registry key', function (): void {
    /** @var PaymentGatewayRegistry $registry */
    $registry               = app( PaymentGatewayRegistry::class );
    $misregistered          = new RefundServiceTestFakeGateway();
    $misregistered->keyName = 'not-mismatch';
    $registry->register( 'mismatch', $misregistered );

    expect( fn () => $registry->get( 'mismatch' ) )
        ->toThrow( RuntimeException::class );
} );

it( 'rejects direct updates and deletes on Refund and RefundItem rows', function (): void {
    $order = makeRefundableOrder( [ [ 'qty' => 1, 'unit' => 1_000 ] ] );
    $item  = $order->items->first();

    $refund = $this->service->issue(
        $order,
        [ [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 1_000 ] ],
    );

    expect( fn () => $refund->update( [ 'reason' => 'tamper' ] ) )
        ->toThrow( LogicException::class );

    $refundItem = $refund->items->first();

    expect( fn () => $refundItem->update( [ 'quantity' => 99 ] ) )
        ->toThrow( LogicException::class );

    expect( fn () => $refundItem->delete() )
        ->toThrow( LogicException::class );

    expect( fn () => $refund->delete() )
        ->toThrow( LogicException::class );

    // Bulk paths through the builder are guarded too.
    expect( fn () => Refund::query()->where( 'id', $refund->id )->update( [ 'reason' => 'bulk' ] ) )
        ->toThrow( LogicException::class );

    expect( fn () => RefundItem::query()->where( 'refund_id', $refund->id )->delete() )
        ->toThrow( LogicException::class );
} );
