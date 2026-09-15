<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\OrderEdited;
use ArtisanPackUI\Ecommerce\Exceptions\OrderNotEditableException;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderEdit;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Services\OrderEditService;
use ArtisanPackUI\Ecommerce\ValueObjects\OrderEditResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->service = app( OrderEditService::class );
} );

function makeOrderWithItems( array $lines = [ [ 'qty' => 2, 'unit' => 1_500 ] ], array $orderOverrides = [] ): Order
{
    $order = Order::factory()->create( array_merge( [
        'subtotal_amount' => 0,
        'total_amount'    => 0,
    ], $orderOverrides ) );

    foreach ( $lines as $line ) {
        $qty  = (int) ( $line['qty'] ?? 1 );
        $unit = (int) ( $line['unit'] ?? 1_000 );

        OrderItem::factory()->create( [
            'order_id'          => $order->id,
            'quantity'          => $qty,
            'unit_price_amount' => $unit,
            'total_amount'      => $qty * $unit,
        ] );
    }

    $order->refresh();
    $order->subtotal_amount = $order->items->sum( fn ( $i ) => $i->unit_price_amount * $i->quantity );
    $order->total_amount    = $order->subtotal_amount;
    $order->save();

    return $order->fresh( 'items' );
}

it( 'applies scalar field edits, writes an audit row + timeline entry, and dispatches OrderEdited', function (): void {
    Event::fake( [ OrderEdited::class ] );

    $order = makeOrderWithItems();

    $result = $this->service->apply(
        $order,
        [
            'shipping_address' => [ 'line1' => '742 Evergreen Terrace' ],
            'customer_note'    => 'Leave at the side door.',
        ],
        actorUserId: 99,
        reason: 'Customer called in with new address.',
    );

    expect( $result )->toBeInstanceOf( OrderEditResult::class );
    expect( $result->order->shipping_address )->toBe( [ 'line1' => '742 Evergreen Terrace' ] );
    expect( $result->order->customer_note )->toBe( 'Leave at the side door.' );

    $edit = OrderEdit::query()->where( 'order_id', $order->id )->sole();
    expect( $edit->actor_user_id )->toBe( 99 );
    expect( $edit->reason )->toBe( 'Customer called in with new address.' );
    expect( $edit->diff['fields']['shipping_address']['before'] )->toBeNull();
    expect( $edit->diff['fields']['shipping_address']['after'] )->toBe( [ 'line1' => '742 Evergreen Terrace' ] );
    expect( $edit->pre_edit_snapshot['fields']['customer_note'] )->toBeNull();

    $timeline = OrderTimelineEntry::query()->where( 'order_id', $order->id )->sole();
    expect( $timeline->event_type )->toBe( 'order.edited' );
    expect( $timeline->payload['order_edit_id'] )->toBe( $edit->id );

    Event::assertDispatched(
        OrderEdited::class,
        fn ( OrderEdited $event ) => $event->order->is( $result->order ) && $event->edit->is( $edit ),
    );
} );

it( 'recomputes subtotal + total when a line quantity changes and returns paymentActionRequired for higher totals', function (): void {
    $order = makeOrderWithItems( [ [ 'qty' => 2, 'unit' => 1_500 ] ] );
    $item  = $order->items->first();

    expect( $order->subtotal_amount )->toBe( 3_000 );
    expect( $order->total_amount )->toBe( 3_000 );

    $result = $this->service->apply( $order, [
        'items' => [
            'change' => [ $item->id => [ 'quantity' => 5 ] ],
        ],
    ] );

    expect( $result->order->subtotal_amount )->toBe( 7_500 );
    expect( $result->order->total_amount )->toBe( 7_500 );
    expect( $result->paymentActionRequired )->toBe( [ 'delta_amount' => 4_500, 'currency' => 'USD' ] );
    expect( $result->refundDelta )->toBeNull();
    expect( $result->diff['items']['changed'][ $item->id ]['quantity'] )
        ->toBe( [ 'before' => 2, 'after' => 5 ] );
    expect( $result->diff['totals']['before']['total_amount'] )->toBe( 3_000 );
    expect( $result->diff['totals']['after']['total_amount'] )->toBe( 7_500 );
} );

it( 'reports a refund delta when the new total is lower than the pre-edit total', function (): void {
    $order = makeOrderWithItems( [ [ 'qty' => 3, 'unit' => 2_000 ] ] );
    $item  = $order->items->first();

    $result = $this->service->apply( $order, [
        'items' => [
            'change' => [ $item->id => [ 'quantity' => 1 ] ],
        ],
    ] );

    expect( $result->refundDelta )->toBe( [ 'delta_amount' => 4_000, 'currency' => 'USD' ] );
    expect( $result->paymentActionRequired )->toBeNull();
} );

it( 'adds and removes line items, reflected in the diff and totals', function (): void {
    $order = makeOrderWithItems( [
        [ 'qty' => 1, 'unit' => 1_000 ],
        [ 'qty' => 2, 'unit' => 500 ],
    ] );

    $keptId    = $order->items->first()->id;
    $removedId = $order->items->last()->id;
    $product   = ArtisanPackUI\Ecommerce\Models\Product::factory()->create();

    $result = $this->service->apply( $order, [
        'items' => [
            'remove' => [ $removedId ],
            'add'    => [
                [
                    'product_id'          => $product->id,
                    'quantity'            => 3,
                    'unit_price_amount'   => 400,
                    'unit_price_currency' => 'USD',
                    'product_snapshot'    => [ 'name' => 'Add-on', 'sku' => 'X-1', 'type' => 'simple', 'options' => [] ],
                ],
            ],
        ],
    ] );

    expect( $result->order->items )->toHaveCount( 2 );
    expect( $result->order->subtotal_amount )->toBe( 1_000 + 1_200 );

    expect( OrderItem::query()->find( $removedId ) )->toBeNull();
    expect( OrderItem::query()->find( $keptId ) )->not->toBeNull();

    expect( $result->diff['items']['removed'] )->toHaveCount( 1 );
    expect( $result->diff['items']['removed'][0]['id'] )->toBe( $removedId );
    expect( $result->diff['items']['added'] )->toHaveCount( 1 );
    expect( $result->diff['items']['added'][0]['product_id'] )->toBe( $product->id );
} );

it( 'runs the ap.ecommerce.order.editing filter before applying the edit', function (): void {
    addFilter( 'ap.ecommerce.order.editing', function ( array $edit, Order $order ): array {
        $edit['customer_note'] = 'Rewritten by filter.';
        return $edit;
    } );

    $order = makeOrderWithItems();

    $result = $this->service->apply( $order, [ 'customer_note' => 'Original.' ] );

    expect( $result->order->customer_note )->toBe( 'Rewritten by filter.' );
} );

it( 'lets the ap.ecommerce.order.recomputingTotals filter override tax/shipping on the mutated order', function (): void {
    addFilter( 'ap.ecommerce.order.recomputingTotals', function ( Order $order ): Order {
        $order->tax_amount      = 250;
        $order->shipping_amount = 750;
        return $order;
    } );

    $order = makeOrderWithItems( [ [ 'qty' => 1, 'unit' => 2_000 ] ] );

    $result = $this->service->apply( $order, [ 'customer_note' => 'trigger recompute' ] );

    expect( $result->order->tax_amount )->toBe( 250 );
    expect( $result->order->shipping_amount )->toBe( 750 );
    expect( $result->order->total_amount )->toBe( 2_000 + 250 + 750 );
} );

it( 'refuses line-item edits on an order past unfulfilled', function (): void {
    $order = makeOrderWithItems( [], [ 'fulfillment_status' => 'partially_fulfilled' ] );

    $this->service->apply( $order, [
        'items' => [ 'add' => [ [
            'product_id'          => 1,
            'quantity'            => 1,
            'unit_price_amount'   => 100,
            'unit_price_currency' => 'USD',
            'product_snapshot'    => [ 'name' => 'x', 'sku' => 'x', 'type' => 'simple', 'options' => [] ],
        ] ] ],
    ] );
} )->throws( OrderNotEditableException::class );

it( 'still allows address + note edits on an order past unfulfilled', function (): void {
    $order = makeOrderWithItems( [ [ 'qty' => 1, 'unit' => 1_000 ] ], [ 'fulfillment_status' => 'fulfilled' ] );

    $result = $this->service->apply( $order, [
        'shipping_address' => [ 'line1' => 'Corrected address' ],
        'customer_note'    => 'Correction after delivery attempt.',
    ] );

    expect( $result->order->shipping_address )->toBe( [ 'line1' => 'Corrected address' ] );
} );

it( 'rolls back a prior edit by writing a new append-only row that restores the pre-edit snapshot', function (): void {
    $order = makeOrderWithItems( [ [ 'qty' => 2, 'unit' => 1_500 ] ] );
    $item  = $order->items->first();

    $first = $this->service->apply( $order, [
        'customer_note' => 'First change.',
        'items'         => [ 'change' => [ $item->id => [ 'quantity' => 4 ] ] ],
    ] );

    expect( $first->order->customer_note )->toBe( 'First change.' );
    expect( $first->order->total_amount )->toBe( 6_000 );

    $rollback = $this->service->rollback( $first->edit, actorUserId: 7 );

    expect( $rollback->order->customer_note )->toBeNull();
    expect( $rollback->order->total_amount )->toBe( 3_000 );
    expect( $rollback->order->items->first()->quantity )->toBe( 2 );

    expect( OrderEdit::query()->where( 'order_id', $order->id )->count() )->toBe( 2 );
    expect( $rollback->diff['rollback_of_edit_id'] )->toBe( $first->edit->id );
    expect( OrderTimelineEntry::query()->where( 'order_id', $order->id )->count() )->toBe( 2 );
} );

it( 'rejects a negative quantity on items.change', function (): void {
    $order = makeOrderWithItems();
    $item  = $order->items->first();

    $this->service->apply( $order, [
        'items' => [ 'change' => [ $item->id => [ 'quantity' => -1 ] ] ],
    ] );
} )->throws( InvalidArgumentException::class, 'positive integer' );

it( 'rejects a negative money field on items.change', function (): void {
    $order = makeOrderWithItems();
    $item  = $order->items->first();

    $this->service->apply( $order, [
        'items' => [ 'change' => [ $item->id => [ 'unit_price_amount' => -100 ] ] ],
    ] );
} )->throws( InvalidArgumentException::class, 'non-negative' );

it( 'rejects a negative order-level discount override', function (): void {
    $order = makeOrderWithItems();

    $this->service->apply( $order, [ 'discount_amount' => -50 ] );
} )->throws( InvalidArgumentException::class, 'non-negative' );

it( 'caps items.add at the per-call limit', function (): void {
    $order   = makeOrderWithItems();
    $product = ArtisanPackUI\Ecommerce\Models\Product::factory()->create();

    $add = array_fill( 0, 201, [
        'product_id'          => $product->id,
        'quantity'            => 1,
        'unit_price_amount'   => 100,
        'unit_price_currency' => 'USD',
        'product_snapshot'    => [ 'name' => 'x', 'sku' => 'x', 'type' => 'simple', 'options' => [] ],
    ] );

    $this->service->apply( $order, [ 'items' => [ 'add' => $add ] ] );
} )->throws( InvalidArgumentException::class, 'per-call limit' );

it( 'restores removed items during rollback', function (): void {
    $order = makeOrderWithItems( [
        [ 'qty' => 1, 'unit' => 1_000 ],
        [ 'qty' => 2, 'unit' => 500 ],
    ] );
    $removedId = $order->items->last()->id;

    $first = $this->service->apply( $order, [
        'items' => [ 'remove' => [ $removedId ] ],
    ] );

    expect( OrderItem::query()->find( $removedId ) )->toBeNull();

    $rollback = $this->service->rollback( $first->edit );

    $restored = OrderItem::query()->find( $removedId );
    expect( $restored )->not->toBeNull();
    expect( $restored->quantity )->toBe( 2 );
    expect( $rollback->order->subtotal_amount )->toBe( 1_000 + 1_000 );
} );
