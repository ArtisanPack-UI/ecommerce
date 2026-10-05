<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\ActivityLogEntry;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerAddress;
use ArtisanPackUI\Ecommerce\Models\CustomerClaimAttempt;
use ArtisanPackUI\Ecommerce\Models\CustomerNote;
use ArtisanPackUI\Ecommerce\Models\CustomerNotificationPreference;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\DigitalDownloadEvent;
use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use ArtisanPackUI\Ecommerce\Models\InboundWebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\LicenseActivation;
use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderEdit;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\OrderNote;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Models\PromotionUsage;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Services\ActivityLogService;
use ArtisanPackUI\Ecommerce\Services\CustomerNoteService;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use ArtisanPackUI\Ecommerce\Services\OrderEditService;
use ArtisanPackUI\Ecommerce\Services\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->service  = app( CustomerService::class );
    $this->customer = Customer::factory()->create( [
        'email'      => 'jane@example.com',
        'first_name' => 'Jane',
        'last_name'  => 'Doe',
        'phone'      => '555-0100',
    ] );
} );

afterEach( function (): void {
    removeAllActions( 'ap.ecommerce.customer.deleted' );
} );

/**
 * Revenue figures per currency, the way a sales report would sum them.
 *
 * @return array<string, array<string, int>>
 */
function revenueByCurrency(): array
{
    return Order::query()
        ->selectRaw( 'currency, COUNT(*) as orders, SUM(subtotal_amount) as subtotal, SUM(discount_amount) as discount, SUM(tax_amount) as tax, SUM(shipping_amount) as shipping, SUM(total_amount) as total, SUM(total_refunded_amount) as refunded' )
        ->groupBy( 'currency' )
        ->orderBy( 'currency' )
        ->get()
        ->mapWithKeys( fn ( $row ): array => [ $row->currency => array_map( 'intval', [
            'orders'   => $row->orders,
            'subtotal' => $row->subtotal,
            'discount' => $row->discount,
            'tax'      => $row->tax,
            'shipping' => $row->shipping,
            'total'    => $row->total,
            'refunded' => $row->refunded,
        ] ) ] )
        ->all();
}

it( 'anonymizes the customer\'s orders and keeps revenue unchanged', function (): void {
    $address = [ 'first_name' => 'Jane', 'address1' => '1 Main St', 'city' => 'Springfield', 'country_code' => 'US' ];

    $orders = Order::factory()->count( 2 )->forCustomer( $this->customer )->create( [
        'email'                 => 'jane@example.com',
        'phone'                 => '555-0100',
        'shipping_address'      => $address,
        'billing_address'       => $address,
        'ip_address'            => '203.0.113.9',
        'user_agent'            => 'Mozilla/5.0',
        'customer_note'         => 'Leave at the back door',
        'subtotal_amount'       => 4_000,
        'tax_amount'            => 300,
        'shipping_amount'       => 700,
        'total_amount'          => 5_000,
        'total_refunded_amount' => 1_000,
    ] );
    Order::factory()->create( [ 'currency' => 'EUR', 'total_amount' => 9_900, 'total_currency' => 'EUR' ] );

    $before = revenueByCurrency();

    $this->service->delete( $this->customer, 7 );

    expect( revenueByCurrency() )->toBe( $before )
        ->and( Customer::query()->find( $this->customer->id ) )->toBeNull();

    foreach ( $orders as $order ) {
        $fresh = $order->fresh();

        expect( $fresh->customer_id )->toBeNull()
            ->and( $fresh->email )->toBe( CustomerService::ANONYMIZED_EMAIL )
            ->and( $fresh->phone )->toBeNull()
            ->and( $fresh->shipping_address )->toBeNull()
            ->and( $fresh->billing_address )->toBeNull()
            ->and( $fresh->ip_address )->toBeNull()
            ->and( $fresh->user_agent )->toBeNull()
            ->and( $fresh->customer_note )->toBeNull()
            ->and( (int) $fresh->total_amount )->toBe( 5_000 )
            ->and( $fresh->order_number )->toBe( $order->order_number );
    }
} );

it( 'deletes addresses, preferences, claim attempts, and notes', function (): void {
    CustomerAddress::factory()->count( 2 )->create( [ 'customer_id' => $this->customer->id ] );
    CustomerNotificationPreference::factory()->create( [ 'customer_id' => $this->customer->id ] );
    CustomerClaimAttempt::factory()->create( [ 'customer_id' => $this->customer->id ] );
    app( CustomerNoteService::class )->add( $this->customer, 'Called about a late parcel' );

    $other = Customer::factory()->create();
    CustomerAddress::factory()->create( [ 'customer_id' => $other->id ] );

    $this->service->delete( $this->customer );

    expect( CustomerAddress::query()->where( 'customer_id', $this->customer->id )->count() )->toBe( 0 )
        ->and( CustomerNotificationPreference::query()->where( 'customer_id', $this->customer->id )->count() )->toBe( 0 )
        ->and( CustomerClaimAttempt::query()->where( 'customer_id', $this->customer->id )->count() )->toBe( 0 )
        ->and( CustomerNote::query()->where( 'customer_id', $this->customer->id )->count() )->toBe( 0 )
        ->and( CustomerAddress::query()->where( 'customer_id', $other->id )->count() )->toBe( 1 );
} );

it( 'detaches carts, reviews, and promotion usages without deleting them', function (): void {
    $cart   = Cart::factory()->create( [ 'customer_id' => $this->customer->id, 'email' => 'jane@example.com' ] );
    $review = ProductReview::factory()->create( [
        'customer_id'  => $this->customer->id,
        'author_name'  => 'Jane Doe',
        'author_email' => 'jane@example.com',
    ] );
    $usage = PromotionUsage::factory()->create( [ 'customer_id' => $this->customer->id ] );

    $this->service->delete( $this->customer );

    expect( $cart->fresh()->customer_id )->toBeNull()
        ->and( $cart->fresh()->email )->toBeNull()
        ->and( $review->fresh()->customer_id )->toBeNull()
        ->and( $review->fresh()->author_name )->toBe( CustomerService::ANONYMIZED_NAME )
        ->and( $review->fresh()->author_email )->toBeNull()
        ->and( $review->fresh()->rating )->toBe( $review->rating )
        ->and( $usage->fresh()->customer_id )->toBeNull()
        ->and( (int) $usage->fresh()->amount_discounted )->toBe( (int) $usage->amount_discounted );
} );

it( 'redacts order notes, note excerpts, and the order-edit history', function (): void {
    $order = Order::factory()->forCustomer( $this->customer )->create();

    $note = OrderNote::factory()->create( [ 'order_id' => $order->id, 'body' => 'Jane called from 555-0100' ] );
    OrderTimelineEntry::query()->create( [
        'order_id'   => $order->id,
        'event_type' => 'note.added',
        'payload'    => [ 'note_id' => $note->id, 'excerpt' => 'Jane called from 555-0100', 'is_customer_visible' => false ],
    ] );
    OrderTimelineEntry::query()->create( [
        'order_id'   => $order->id,
        'event_type' => 'order.refunded',
        'payload'    => [ 'amount' => 500, 'reason' => 'Damaged' ],
    ] );
    $edit = OrderEdit::factory()->create( [
        'order_id'          => $order->id,
        'diff'              => [ 'fields' => [ 'email' => [ 'before' => 'old@example.com', 'after' => 'jane@example.com' ] ], 'items' => [], 'totals' => [] ],
        'pre_edit_snapshot' => [ 'fields' => [ 'email' => 'old@example.com', 'phone' => '555-0100', 'shipping_method_key' => 'flat' ], 'items' => [], 'totals' => [] ],
    ] );

    $this->service->delete( $this->customer );

    $timeline = DB::table( 'ecommerce_order_timeline_entries' )->where( 'order_id', $order->id )->orderBy( 'id' )->pluck( 'payload' )->map( fn ( $p ) => json_decode( $p, true ) );
    $editRow  = DB::table( 'ecommerce_order_edits' )->where( 'id', $edit->id )->first();
    $snapshot = json_decode( $editRow->pre_edit_snapshot, true );
    $diff     = json_decode( $editRow->diff, true );

    expect( $note->fresh()->body )->toBe( CustomerService::REDACTED )
        ->and( $timeline[0]['excerpt'] )->toBe( CustomerService::REDACTED )
        ->and( $timeline[0]['note_id'] )->toBe( $note->id )
        ->and( $timeline[1] )->toBe( [ 'amount' => 500, 'reason' => CustomerService::REDACTED ] )
        ->and( $snapshot['fields']['email'] )->toBe( CustomerService::ANONYMIZED_EMAIL )
        ->and( $snapshot['fields']['phone'] )->toBeNull()
        ->and( $snapshot['fields']['shipping_method_key'] )->toBe( 'flat' )
        ->and( $diff['fields']['email'] )->toBe( [ 'before' => CustomerService::ANONYMIZED_EMAIL, 'after' => CustomerService::ANONYMIZED_EMAIL ] );
} );

it( 'drops IP addresses from download events and license activations but keeps access', function (): void {
    $order    = Order::factory()->forCustomer( $this->customer )->create();
    $item     = OrderItem::factory()->create( [ 'order_id' => $order->id ] );
    $download = DigitalDownload::factory()->create( [ 'order_item_id' => $item->id ] );
    $event    = DigitalDownloadEvent::query()->create( [
        'digital_download_id' => $download->id,
        'ip_address'          => '203.0.113.9',
        'user_agent'          => 'curl',
        'event_type'          => DigitalDownloadEvent::TYPE_DOWNLOAD,
    ] );
    $license    = LicenseKey::factory()->create( [ 'order_item_id' => $item->id ] );
    $activation = LicenseActivation::factory()->create( [ 'license_key_id' => $license->id, 'ip_address' => '203.0.113.9' ] );

    $this->service->delete( $this->customer );

    expect( $event->fresh()->ip_address )->toBeNull()
        ->and( $event->fresh()->user_agent )->toBeNull()
        ->and( $activation->fresh()->ip_address )->toBeNull()
        ->and( $download->fresh() )->not->toBeNull()
        ->and( $license->fresh()->is_revoked )->toBeFalse();
} );

it( 'scrubs the activity log and records a customer.deleted entry without personal data', function (): void {
    $this->customer->update( [ 'phone' => '555-0199' ] );

    $this->service->delete( $this->customer, 3 );

    $entries = ActivityLogEntry::query()->forSubject( $this->customer )->orderBy( 'id' )->get();
    $deleted = $entries->firstWhere( 'event_type', 'customer.deleted' );
    $encoded = json_encode( $entries->pluck( 'payload' )->all() );

    expect( $encoded )->not->toContain( 'jane@example.com' )
        ->and( $encoded )->not->toContain( '555-0199' )
        ->and( $encoded )->not->toContain( 'Jane' )
        ->and( $entries->where( 'event_type', 'customer.deleted' ) )->toHaveCount( 1 )
        ->and( $deleted->actor_user_id )->toBe( 3 )
        ->and( $deleted->payload )->toBe( [ 'anonymized' => true, 'orders' => 0 ] );
} );

it( 'fires ap.ecommerce.customer.deleted after commit with the customer and a summary', function (): void {
    Order::factory()->forCustomer( $this->customer )->create();
    CustomerAddress::factory()->create( [ 'customer_id' => $this->customer->id ] );

    $received = null;
    addAction( 'ap.ecommerce.customer.deleted', function ( Customer $customer, array $summary ) use ( &$received ): void {
        $received = [ $customer->id, $customer->email, $summary, DB::transactionLevel() ];
    }, 10, 2 );

    $id = $this->customer->id;
    $this->service->delete( $this->customer );

    expect( $received[0] )->toBe( $id )
        ->and( $received[1] )->toBe( 'jane@example.com' )
        ->and( $received[2]['orders'] )->toBe( 1 )
        ->and( $received[2]['addresses'] )->toBe( 1 );
} );

it( 'waits for an outer transaction to commit before firing the deleted hook', function (): void {
    $fired = false;
    addAction( 'ap.ecommerce.customer.deleted', function () use ( &$fired ): void {
        $fired = true;
    } );

    DB::transaction( function () use ( &$fired ): void {
        $this->service->delete( $this->customer );

        expect( $fired )->toBeFalse();
    } );

    expect( $fired )->toBeTrue();
} );

it( 'never fires the deleted hook when the outer transaction rolls back', function (): void {
    $fired = false;
    addAction( 'ap.ecommerce.customer.deleted', function () use ( &$fired ): void {
        $fired = true;
    } );

    try {
        DB::transaction( function (): void {
            $this->service->delete( $this->customer );

            throw new RuntimeException( 'abort' );
        } );
    } catch ( RuntimeException ) {
    }

    expect( $fired )->toBeFalse()
        ->and( Customer::query()->find( $this->customer->id ) )->not->toBeNull();
} );

it( 'anonymizes unclaimed guest orders, carts, and reviews under the customer\'s email', function (): void {
    $guestOrder = Order::factory()->guest()->create( [
        'email'            => 'JANE@Example.com',
        'is_claimed'       => false,
        'shipping_address' => [ 'address1' => '1 Main St' ],
        'total_amount'     => 3_000,
    ] );
    $guestCart   = Cart::factory()->create( [ 'customer_id' => null, 'email' => 'jane@example.com' ] );
    $guestReview = ProductReview::factory()->create( [ 'customer_id' => null, 'author_name' => 'Jane', 'author_email' => 'Jane@Example.com' ] );

    $other         = Customer::factory()->create( [ 'email' => 'someone@example.com' ] );
    $otherOrder    = Order::factory()->guest()->create( [ 'email' => 'someone@example.com' ] );
    $linkedElse    = Order::factory()->forCustomer( $other )->create( [ 'email' => 'jane@example.com' ] );
    $strangerCart  = Cart::factory()->create( [ 'customer_id' => null, 'email' => 'someone@example.com' ] );

    $received = null;
    addAction( 'ap.ecommerce.customer.deleted', function ( Customer $customer, array $summary ) use ( &$received ): void {
        $received = $summary;
    }, 10, 2 );

    $this->service->delete( $this->customer );

    expect( $guestOrder->fresh()->email )->toBe( CustomerService::ANONYMIZED_EMAIL )
        ->and( $guestOrder->fresh()->shipping_address )->toBeNull()
        ->and( (int) $guestOrder->fresh()->total_amount )->toBe( 3_000 )
        ->and( $guestCart->fresh()->email )->toBeNull()
        ->and( $guestReview->fresh()->author_email )->toBeNull()
        ->and( $guestReview->fresh()->author_name )->toBe( CustomerService::ANONYMIZED_NAME )
        ->and( $otherOrder->fresh()->email )->toBe( 'someone@example.com' )
        ->and( $linkedElse->fresh()->email )->toBe( 'jane@example.com' )
        ->and( $linkedElse->fresh()->customer_id )->toBe( $other->id )
        ->and( $strangerCart->fresh()->email )->toBe( 'someone@example.com' )
        ->and( $received['orders'] )->toBe( 1 )
        ->and( $received['carts'] )->toBe( 1 )
        ->and( $received['reviews'] )->toBe( 1 );
} );

it( 'redacts refund, edit, status, and fraud reasons on the anonymized orders', function (): void {
    $order      = Order::factory()->forCustomer( $this->customer )->create();
    $kept       = Order::factory()->create();
    $refund     = Refund::factory()->create( [ 'order_id' => $order->id, 'reason' => 'Jane said it arrived broken' ] );
    $keptRefund = Refund::factory()->create( [ 'order_id' => $kept->id, 'reason' => 'Damaged' ] );
    $edit       = OrderEdit::factory()->create( [ 'order_id' => $order->id, 'reason' => 'Jane moved house' ] );

    foreach ( [
        'order.status_changed'     => [ 'from' => 'pending', 'to' => 'processing', 'reason' => 'Jane called' ],
        'order.cancelled'          => [ 'from' => 'pending', 'reason' => 'Jane changed her mind', 'refund_owed' => 0 ],
        'payment.fraud_challenged' => [ 'verdict' => 'review', 'score' => 70, 'reasons' => [ 'email jane@example.com seen before' ] ],
    ] as $type => $payload ) {
        OrderTimelineEntry::query()->create( [ 'order_id' => $order->id, 'event_type' => $type, 'payload' => $payload ] );
    }

    $this->service->delete( $this->customer );

    $payloads = DB::table( 'ecommerce_order_timeline_entries' )->where( 'order_id', $order->id )->orderBy( 'id' )->pluck( 'payload' )->map( fn ( $p ) => json_decode( $p, true ) )->all();

    expect( DB::table( 'ecommerce_refunds' )->where( 'id', $refund->id )->value( 'reason' ) )->toBe( CustomerService::REDACTED )
        ->and( DB::table( 'ecommerce_refunds' )->where( 'id', $keptRefund->id )->value( 'reason' ) )->toBe( 'Damaged' )
        ->and( DB::table( 'ecommerce_order_edits' )->where( 'id', $edit->id )->value( 'reason' ) )->toBe( CustomerService::REDACTED )
        ->and( $payloads[0] )->toBe( [ 'from' => 'pending', 'to' => 'processing', 'reason' => CustomerService::REDACTED ] )
        ->and( $payloads[1]['reason'] )->toBe( CustomerService::REDACTED )
        ->and( $payloads[1]['refund_owed'] )->toBe( 0 )
        ->and( $payloads[2]['reasons'] )->toBe( [ CustomerService::REDACTED ] )
        ->and( $payloads[2]['score'] )->toBe( 70 );
} );

it( 'restores no personal data when an order edit is rolled back after a delete', function (): void {
    $order = Order::factory()->forCustomer( $this->customer )->create( [
        'email'            => 'jane@example.com',
        'phone'            => '555-0100',
        'shipping_address' => [ 'address1' => '2 New St' ],
    ] );
    $edit = OrderEdit::factory()->create( [
        'order_id'          => $order->id,
        'diff'              => [ 'fields' => [ 'shipping_address' => [ 'before' => [ 'address1' => '1 Old St' ], 'after' => [ 'address1' => '2 New St' ] ] ], 'items' => [], 'totals' => [] ],
        'pre_edit_snapshot' => [
            'fields' => [ 'email' => 'jane@example.com', 'phone' => '555-0100', 'customer_note' => 'Ring twice', 'shipping_address' => [ 'address1' => '1 Old St' ], 'billing_address' => null, 'shipping_method_key' => null ],
            'items'  => [],
            'totals' => [ 'subtotal_amount' => 5_000, 'discount_amount' => 0, 'tax_amount' => 0, 'shipping_amount' => 0, 'total_amount' => 5_000, 'currency' => 'USD' ],
        ],
    ] );

    $this->service->delete( $this->customer );

    app( OrderEditService::class )->rollback( $edit->fresh() );

    $fresh = $order->fresh();

    expect( $fresh->email )->toBe( CustomerService::ANONYMIZED_EMAIL )
        ->and( $fresh->phone )->toBeNull()
        ->and( $fresh->customer_note )->toBeNull()
        ->and( $fresh->shipping_address )->toBeNull();
} );

it( 'redacts outbound webhook deliveries found through their subject columns and keeps the rest', function (): void {
    Queue::fake();
    WebhookSubscription::factory()->create( [ 'events' => [ 'order.refunded', 'refund.created', 'cart.created' ] ] );

    $order = Order::factory()->forCustomer( $this->customer )->create( [ 'email' => 'jane@example.com' ] );
    $other = Order::factory()->create( [ 'email' => 'bob@example.com' ] );
    $hooks = app( WebhookDispatcher::class );

    $about      = $hooks->dispatch( 'order.refunded', [ 'order' => [ 'type' => 'order', 'id' => $order->id, 'customer_id' => $this->customer->id, 'email' => 'jane@example.com', 'shipping_address' => [ 'address1' => '1 Main St' ], 'total_amount' => 5_000 ], 'reason' => 'Jane asked' ] )->first();
    $refundOnly = $hooks->dispatch( 'refund.created', [ 'refund' => [ 'type' => 'refund', 'id' => 9, 'order_id' => $order->id, 'reason' => 'Jane asked' ] ] )->first();
    $cartOnly   = $hooks->dispatch( 'cart.created', [ 'cart' => [ 'type' => 'cart', 'id' => 3, 'customer_id' => $this->customer->id, 'email' => 'jane@example.com' ] ] )->first();
    $unrelated  = $hooks->dispatch( 'order.refunded', [ 'order' => [ 'type' => 'order', 'id' => $other->id, 'email' => 'bob@example.com' ] ] )->first();

    DB::table( 'ecommerce_webhook_deliveries' )->whereIn( 'id', [ $about->id, $unrelated->id ] )->update( [ 'response_body' => 'ok' ] );

    expect( $about->order_id )->toBe( $order->id )
        ->and( $about->customer_id )->toBe( $this->customer->id )
        ->and( $refundOnly->order_id )->toBe( $order->id )
        ->and( $refundOnly->customer_id )->toBeNull()
        ->and( $cartOnly->order_id )->toBeNull()
        ->and( $cartOnly->customer_id )->toBe( $this->customer->id );

    $this->service->delete( $this->customer );

    $aboutRow = DB::table( 'ecommerce_webhook_deliveries' )->where( 'id', $about->id )->first();
    $payload  = json_decode( $aboutRow->payload, true );
    $decode   = fn ( int $id ): array => json_decode( DB::table( 'ecommerce_webhook_deliveries' )->where( 'id', $id )->value( 'payload' ), true );

    expect( $payload['data']['order']['email'] )->toBe( CustomerService::REDACTED )
        ->and( $payload['data']['order']['shipping_address'] )->toBeNull()
        ->and( $payload['data']['order']['total_amount'] )->toBe( 5_000 )
        ->and( $payload['data']['reason'] )->toBe( CustomerService::REDACTED )
        ->and( $aboutRow->response_body )->toBeNull()
        ->and( $aboutRow->payload_hash )->toBe( hash( 'sha256', (string) json_encode( $payload, WebhookDispatcher::JSON_FLAGS ) ) )
        ->and( $decode( $refundOnly->id )['data']['refund']['reason'] )->toBe( CustomerService::REDACTED )
        ->and( $decode( $cartOnly->id )['data']['cart']['email'] )->toBe( CustomerService::REDACTED )
        ->and( $decode( $unrelated->id )['data']['order']['email'] )->toBe( 'bob@example.com' )
        ->and( DB::table( 'ecommerce_webhook_deliveries' )->where( 'id', $unrelated->id )->value( 'response_body' ) )->toBe( 'ok' );
} );

it( 'forgets stored idempotent responses from the customer\'s own routes only', function (): void {
    $record = fn ( string $endpoint, array $data ): IdempotencyRecord => IdempotencyRecord::query()->create( [
        'actor_scope'     => 'user:1',
        'endpoint_key'    => $endpoint,
        'idempotency_key' => (string) Str::uuid(),
        'request_hash'    => str_repeat( 'a', 64 ),
        'response_status' => 200,
        'response_body'   => json_encode( [ 'data' => $data ] ),
        'expires_at'      => now()->addDay(),
    ] );

    $other       = Customer::factory()->create();
    $update      = $record( 'ecommerce.api.customers.update', [ 'type' => 'customer', 'id' => $this->customer->id, 'email' => 'jane@example.com' ] );
    $address     = $record( 'ecommerce.api.customers.addresses.store', [ 'type' => 'customerAddress', 'id' => 4, 'customer_id' => $this->customer->id ] );
    $theirs      = $record( 'ecommerce.api.customers.update', [ 'type' => 'customer', 'id' => $other->id ] );
    $orderRecord = $record( 'ecommerce.api.orders.refunds.store', [ 'type' => 'refund', 'id' => 1, 'customer_id' => $this->customer->id ] );

    $this->service->delete( $this->customer );

    expect( IdempotencyRecord::query()->find( $update->id ) )->toBeNull()
        ->and( IdempotencyRecord::query()->find( $address->id ) )->toBeNull()
        ->and( IdempotencyRecord::query()->find( $theirs->id ) )->not->toBeNull()
        ->and( IdempotencyRecord::query()->find( $orderRecord->id ) )->not->toBeNull();
} );

it( 'leaves the activity log untouched when it is disabled', function (): void {
    config()->set( 'artisanpack.ecommerce.activity_log.enabled', false );

    $this->service->delete( $this->customer );

    expect( Customer::query()->find( $this->customer->id ) )->toBeNull()
        ->and( ActivityLogEntry::query()->where( 'event_type', 'customer.deleted' )->count() )->toBe( 0 )
        ->and( app( ActivityLogService::class )->enabled() )->toBeFalse();
} );

it( 'drops the stored body of inbound provider webhooks about the customer\'s payments or carrying their email', function (): void {
    $order = Order::factory()->forCustomer( $this->customer )->create( [ 'email' => 'jane@example.com', 'payment_reference' => 'pi_jane' ] );

    $inbound = fn ( array $attributes ): InboundWebhookDelivery => InboundWebhookDelivery::query()->create( $attributes + [
        'provider'        => 'stripe',
        'verified'        => true,
        'payload_hash'    => str_repeat( 'a', 64 ),
        'payload_size'    => 10,
        'parsed'          => [ 'billing' => 'Jane Doe' ],
        'response_status' => 200,
        'received_at'     => now(),
    ] );

    $bySession = $inbound( [ 'session_reference' => 'pi_jane', 'payload' => '{"name":"Jane Doe"}' ] );
    $byEmail   = $inbound( [ 'session_reference' => 'pi_other_ref', 'payload' => '{"receipt_email":"JANE@example.com"}' ] );
    $other     = $inbound( [ 'session_reference' => 'pi_someone', 'payload' => '{"receipt_email":"sam@example.com"}' ] );

    $this->service->delete( $this->customer );

    foreach ( [ $bySession, $byEmail ] as $row ) {
        $row->refresh();

        expect( $row->payload )->toBe( '' )
            ->and( $row->parsed )->toBeNull()
            ->and( $row->payload_truncated )->toBeTrue()
            ->and( $row->payload_hash )->toBe( str_repeat( 'a', 64 ) );
    }

    expect( $other->refresh()->payload )->toBe( '{"receipt_email":"sam@example.com"}' )
        ->and( $order->refresh()->email )->toBe( CustomerService::ANONYMIZED_EMAIL );
} );

it( 'matches LIKE wildcards in the email literally when scrubbing inbound webhooks', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'j_ne%@example.com' ] );

    $row = InboundWebhookDelivery::query()->create( [
        'provider'        => 'stripe',
        'verified'        => true,
        'payload_hash'    => str_repeat( 'b', 64 ),
        'payload'         => '{"receipt_email":"jane-other@example.com"}',
        'response_status' => 200,
        'received_at'     => now(),
    ] );

    $this->service->delete( $customer );

    expect( $row->refresh()->payload )->toBe( '{"receipt_email":"jane-other@example.com"}' );
} );
