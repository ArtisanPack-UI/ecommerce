<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Events\OrderRefunded;
use ArtisanPackUI\Ecommerce\Events\PaymentRefunded;
use ArtisanPackUI\Ecommerce\Exceptions\RefundNotAllowedException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\RefundItem;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Services\RefundService;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

    /** @var array<int, array<string, mixed>> */
    public array $contexts = [];

    /** Runs inside refund(), to observe the caller's state. */
    public ?Closure $onRefund = null;

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

    public function label(): string
    {
        return 'Fake gateway';
    }

    public function supportsRefunds(): bool
    {
        return $this->supports;
    }

    public function supportsPartialRefunds(): bool
    {
        return $this->supportsPartial;
    }

    public function supportsSavedInstruments(): bool
    {
        return false;
    }

    public function createPaymentSession( Cart $cart, array $context = [] ): PaymentSession
    {
        return new PaymentSession(
            gatewayKey: $this->keyName,
            reference: 'ps_fake_' . $cart->getKey(),
            amount: Money::USD( 0 ),
        );
    }

    public function retrievePaymentSession( string $reference ): PaymentSession
    {
        throw new LogicException( 'not used' );
    }

    public function capturePayment( Order $order, PaymentSession $session ): PaymentResult
    {
        return PaymentResult::success( $session->amount, 'pi_fake_captured' );
    }

    public function voidPendingPayment( Order $order ): void
    {
    }

    public function handleWebhook( Request $request ): WebhookResult
    {
        return WebhookResult::unverified( 'not_implemented' );
    }

    public function refund( Order $order, Money $amount, ?string $reason = null, array $context = [] ): RefundResult
    {
        $this->calls[] = [
            'order_id' => $order->id,
            'amount'   => (int) $amount->getAmount(),
            'currency' => $amount->getCurrency()->getCode(),
            'reason'   => $reason,
        ];
        $this->contexts[] = $context;

        if ( null !== $this->onRefund ) {
            ( $this->onRefund )();
        }

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
        'stockable_type'   => ( new Product() )->getMorphClass(),
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
        'stockable_type'   => ( new Product() )->getMorphClass(),
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

it( 'records a failed refund and changes nothing else when the gateway declines', function (): void {
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

    // The attempt is on the ledger as failed; no money moved.
    expect( Refund::query()->sole()->status )->toBe( Refund::STATUS_FAILED );
    expect( $order->fresh()->total_refunded_amount )->toBe( 0 );
    expect( $order->fresh()->payment_status )->toBe( 'paid' );
    expect( OrderTimelineEntry::query()->where( 'event_type', 'refund.failed' )->exists() )->toBeTrue();

    // A failed refund doesn't use up the balance: the refund can be retried.
    $this->gateway->nextResult = null;
    $retry                     = $this->service->issue( $order->fresh(), [ [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 1_000 ] ] );

    expect( $retry->status )->toBe( Refund::STATUS_SUCCEEDED )
        ->and( $order->fresh()->payment_status )->toBe( 'refunded' );

    // Only the successful retry announced a refund.
    Event::assertDispatchedTimes( OrderRefunded::class, 1 );
    Event::assertDispatchedTimes( PaymentRefunded::class, 1 );
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

    // Money moved, but not what was asked: the refund stays pending (and
    // blocks further refunds) until someone reconciles it.
    expect( Refund::query()->sole()->status )->toBe( Refund::STATUS_PENDING );
    expect( $order->fresh()->total_refunded_amount )->toBe( 0 );
    expect( OrderTimelineEntry::query()->where( 'event_type', 'refund.amount_mismatch' )->exists() )->toBeTrue();
    expect( fn () => $this->service->issue( $order->fresh(), [ [ 'order_item_id' => $item->id, 'quantity' => 0, 'amount' => 1 ] ] ) )->toThrow( RefundNotAllowedException::class );
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

it( 'records an amount-only line (quantity 0) without using up refundable units', function (): void {
    $order = makeRefundableOrder( [ [ 'qty' => 1, 'unit' => 2_000 ] ] );
    $order->update( [ 'shipping_amount' => 500, 'total_amount' => 2_500 ] );
    // Placement allocates the order's shipping to its lines.
    $order->items->first()->update( [ 'shipping_amount' => 500, 'total_amount' => 2_500 ] );
    $item = $order->items->first()->fresh();

    $refund = $this->service->issue( $order->fresh(), [
        [ 'order_item_id' => $item->id, 'quantity' => 0, 'amount' => 500 ],
    ], reason: 'Shipping refund' );

    expect( $refund->amount )->toBe( 500 )
        ->and( $refund->items->first()->quantity )->toBe( 0 )
        ->and( $refund->items->first()->shipping_amount )->toBe( 100 )
        ->and( $order->fresh()->payment_status )->toBe( 'partially_refunded' );

    // The unit is still refundable afterwards.
    $this->service->issue( $order->fresh(), [
        [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 2_000 ],
    ] );

    expect( $order->fresh()->payment_status )->toBe( 'refunded' );
} );

it( 'refuses to restock an amount-only line', function (): void {
    $order = makeRefundableOrder();
    $item  = $order->items->first();

    $this->service->issue( $order, [
        [ 'order_item_id' => $item->id, 'quantity' => 0, 'amount' => 100, 'restock' => true ],
    ] );
} )->throws( InvalidArgumentException::class, 'cannot restock' );

it( 'restocks under a host that enforces a morph map', function (): void {
    // A host with `Relation::enforceMorphMap([...])` of its own.
    Illuminate\Database\Eloquent\Relations\Relation::enforceMorphMap( [] );

    try {
        $product = Product::factory()->create();
        $price   = app( ArtisanPackUI\Ecommerce\Services\ProductService::class )->upsertPrice( $product, [ 'currency' => 'USD', 'price_amount' => 1_000 ] );
        $stock   = app( ArtisanPackUI\Ecommerce\Services\ProductService::class )->inventoryItemFor( $product );
        $stock->update( [ 'quantity_on_hand' => 5 ] );

        $order = makeRefundableOrder( [ [ 'qty' => 2, 'unit' => 1_000, 'product' => $product ] ] );

        $this->service->issue( $order, [ [ 'order_item_id' => $order->items->first()->id, 'quantity' => 2, 'amount' => 2_000, 'restock' => true ] ] );

        expect( $stock->fresh()->quantity_on_hand )->toBe( 7 )
            ->and( $price->fresh()->priceable_type )->toBe( 'ecommerce.product' )
            ->and( $stock->fresh()->stockable_type )->toBe( 'ecommerce.product' )
            ->and( $stock->fresh()->stockable->is( $product ) )->toBeTrue();
    } finally {
        Illuminate\Database\Eloquent\Relations\Relation::requireMorphMap( false );
    }
} );

/*
 * Audit C5/C8 — the gateway runs outside the transaction and refunds are
 * capped per line.
 */

it( 'keeps a succeeded refund when a PaymentRefunded listener throws, and refuses a retry over the balance', function (): void {
    $order = makeRefundableOrder( [ [ 'qty' => 1, 'unit' => 1_000 ] ] );
    $item  = $order->items->first();

    $fail = static function (): void {
        throw new RuntimeException( 'listener blew up' );
    };
    addAction( 'ap.ecommerce.payment.refunded', $fail );

    $refund = $this->service->issue( $order, [ [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 1_000 ] ] );

    removeAction( 'ap.ecommerce.payment.refunded', $fail );

    expect( $refund->status )->toBe( Refund::STATUS_SUCCEEDED )
        ->and( $order->fresh()->total_refunded_amount )->toBe( 1_000 )
        ->and( $this->gateway->calls )->toHaveCount( 1 );

    expect( fn () => $this->service->issue( $order->fresh(), [ [ 'order_item_id' => $item->id, 'quantity' => 0, 'amount' => 1 ] ] ) )
        ->toThrow( RefundNotAllowedException::class );
    expect( $this->gateway->calls )->toHaveCount( 1 );
} );

it( 'calls the gateway outside any database transaction, with the refund id as idempotency key', function (): void {
    $order                   = makeRefundableOrder( [ [ 'qty' => 1, 'unit' => 1_000 ] ] );
    $level                   = null;
    $this->gateway->onRefund = static function () use ( &$level ): void {
        $level = Illuminate\Support\Facades\DB::transactionLevel();
    };

    $refund = $this->service->issue( $order, [ [ 'order_item_id' => $order->items->first()->id, 'quantity' => 1, 'amount' => 1_000 ] ] );

    // RefreshDatabase wraps the test in one transaction of its own.
    expect( $level )->toBe( 1 )
        ->and( $this->gateway->contexts[0] )->toBe( [ 'idempotency_key' => 'ap-ec-refund-' . $refund->id, 'refund_id' => $refund->id ] );
} );

it( 'refuses a line amount above what its order line has left', function (): void {
    $order = makeRefundableOrder( [ [ 'qty' => 2, 'unit' => 1_000 ], [ 'qty' => 1, 'unit' => 5_000 ] ] );
    $small = $order->items->first();

    $this->service->issue( $order, [ [ 'order_item_id' => $small->id, 'quantity' => 0, 'amount' => 2_001 ] ] );
} )->throws( RefundNotAllowedException::class, 'exceeds the 2000 still refundable on that line' );

it( 'refuses a payment.refunding filter that changes the total', function (): void {
    $order = makeRefundableOrder( [ [ 'qty' => 1, 'unit' => 1_000 ] ] );
    addFilter( 'ap.ecommerce.payment.refunding', static fn ( Money $amount ): Money => $amount->multiply( 2 ) );

    expect( fn () => $this->service->issue( $order, [ [ 'order_item_id' => $order->items->first()->id, 'quantity' => 1, 'amount' => 500 ] ] ) )
        ->toThrow( RefundNotAllowedException::class, 'must equal the sum of their lines' );

    expect( Refund::query()->count() )->toBe( 0 )
        ->and( $this->gateway->calls )->toBe( [] );
} );

it( 'stores the tax part of each refund line', function (): void {
    $order = makeRefundableOrder( [ [ 'qty' => 1, 'unit' => 10_000 ] ] );
    $order->items->first()->update( [ 'tax_amount' => 800, 'total_amount' => 10_800 ] );
    $order->update( [ 'tax_amount' => 800, 'total_amount' => 10_800 ] );
    $item = $order->items->first()->fresh();

    $half = $this->service->issue( $order->fresh(), [ [ 'order_item_id' => $item->id, 'quantity' => 0, 'amount' => 5_400 ] ] );
    $rest = $this->service->issue( $order->fresh(), [ [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 5_400 ] ] );

    expect( $half->items->first()->tax_amount )->toBe( 400 )
        ->and( $half->items->first()->tax_amount + $rest->items->first()->tax_amount )->toBe( 800 );
} );
