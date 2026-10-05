<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Mail\NotificationTemplateMail;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerNotificationPreference;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\NotificationTemplate;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Notifications\EcommerceNotification;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Notifications\NotificationDispatcher;
use ArtisanPackUI\Ecommerce\Services\NotificationPreferenceService;
use ArtisanPackUI\Ecommerce\Services\NotificationTemplateService;
use ArtisanPackUI\Ecommerce\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.notifications.admin_emails', [ 'ops@example.test' ] );
} );

function customerOrder( ?Customer $customer = null ): Order
{
    $order = Order::factory()->forCustomer( $customer ?? Customer::factory()->create( [ 'first_name' => 'Ada' ] ) )->create( [ 'order_number' => 'K7QM2XW9' ] );
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_snapshot' => [ 'name' => 'Poster', 'sku' => 'P-1' ] ] );

    return $order;
}

/**
 * The template keys sent to `$notifiable`.
 *
 * @return array<int, string>
 */
function sentTemplates( mixed $notifiable ): array
{
    return Notification::sent( $notifiable, EcommerceNotification::class )->map( fn ( EcommerceNotification $n ): string => $n->templateKey )->values()->all();
}

/**
 * The template keys sent to store staff.
 *
 * @return array<int, string>
 */
function staffTemplates(): array
{
    $keys = [];

    Notification::assertSentOnDemand( EcommerceNotification::class, function ( EcommerceNotification $notification, array $channels, AnonymousNotifiable $notifiable ) use ( &$keys ): bool {
        if ( [ 'ops@example.test' ] === (array) $notifiable->routeNotificationFor( 'mail' ) ) {
            $keys[] = $notification->templateKey;
        }

        return true;
    } );

    return $keys;
}

it( 'sends order lifecycle notifications from the engine hooks', function (): void {
    Notification::fake();
    $order    = customerOrder();
    $customer = $order->customer;

    doAction( 'ap.ecommerce.order.placed', $order );
    doAction( 'ap.ecommerce.order.shipped', $order, Shipment::factory()->create( [ 'order_id' => $order->id, 'carrier' => 'UPS', 'tracking_url' => 'https://ups.test/1Z' ] ) );
    doAction( 'ap.ecommerce.order.statusChanged', $order, 'processing', 'complete' );
    doAction( 'ap.ecommerce.order.statusChanged', $order, 'processing', 'cancelled' );
    doAction( 'ap.ecommerce.order.refunded', $order, Refund::factory()->create( [ 'order_id' => $order->id, 'amount' => 1_500, 'currency' => 'USD' ] ) );

    expect( sentTemplates( $customer ) )->toBe( [
        NotificationCatalog::ORDER_CONFIRMATION,
        NotificationCatalog::ORDER_SHIPPED,
        NotificationCatalog::ORDER_CANCELLED,
        NotificationCatalog::ORDER_REFUNDED,
    ] );

    Notification::assertSentTo( $customer, EcommerceNotification::class, function ( EcommerceNotification $notification ) use ( $customer ): bool {
        if ( NotificationCatalog::ORDER_SHIPPED !== $notification->templateKey ) {
            return false;
        }

        $mail = $notification->toMail( $customer );

        return $mail instanceof NotificationTemplateMail
            && 'Your order K7QM2XW9 is on its way' === $mail->subjectLine
            && str_contains( $mail->htmlBody, 'https://ups.test/1Z' )
            && $mail->hasTo( $customer->email );
    } );
} );

it( 'asks for a review a configurable time after delivery', function (): void {
    Notification::fake();
    config()->set( 'artisanpack.ecommerce.notifications.review_request_delay_days', 5 );
    $order = customerOrder();

    doAction( 'ap.ecommerce.order.delivered', $order, Shipment::factory()->create( [ 'order_id' => $order->id, 'status' => Shipment::STATUS_DELIVERED ] ) );

    expect( sentTemplates( $order->customer ) )->toBe( [ NotificationCatalog::ORDER_DELIVERED, NotificationCatalog::REVIEW_REQUEST ] );

    Notification::assertSentTo( $order->customer, EcommerceNotification::class, fn ( EcommerceNotification $n ): bool => NotificationCatalog::REVIEW_REQUEST === $n->templateKey
        && $n->delay instanceof DateTimeInterface
        && $n->delay->isSameDay( now()->addDays( 5 ) ) );
} );

it( 'sends the delivery notice and review request once, after the last shipment arrives', function (): void {
    Notification::fake();
    $order  = customerOrder();
    $first  = Shipment::factory()->create( [ 'order_id' => $order->id, 'status' => Shipment::STATUS_DELIVERED ] );
    $second = Shipment::factory()->create( [ 'order_id' => $order->id, 'status' => Shipment::STATUS_IN_TRANSIT ] );

    doAction( 'ap.ecommerce.order.delivered', $order, $first );
    Notification::assertNothingSent();

    $second->update( [ 'status' => Shipment::STATUS_DELIVERED ] );
    doAction( 'ap.ecommerce.order.delivered', $order, $second );

    expect( sentTemplates( $order->customer ) )->toBe( [ NotificationCatalog::ORDER_DELIVERED, NotificationCatalog::REVIEW_REQUEST ] );
} );

it( 're-checks preferences and the template switch when a queued notification is delivered', function (): void {
    Notification::fake();
    $order    = customerOrder();
    $customer = $order->customer;

    doAction( 'ap.ecommerce.order.delivered', $order, Shipment::factory()->create( [ 'order_id' => $order->id, 'status' => Shipment::STATUS_DELIVERED ] ) );

    $request = Notification::sent( $customer, EcommerceNotification::class )->first( fn ( EcommerceNotification $n ): bool => NotificationCatalog::REVIEW_REQUEST === $n->templateKey );
    $placed  = new EcommerceNotification( NotificationCatalog::ORDER_CONFIRMATION, 'mail', [] );

    expect( $request->shouldSend( $customer, 'mail' ) )->toBeTrue();

    app( NotificationPreferenceService::class )->update( $customer, [ [ 'channel' => 'mail', 'category' => 'review-requests', 'is_enabled' => false ] ] );
    app( NotificationTemplateService::class )->sync();
    NotificationTemplate::query()->where( 'key', NotificationCatalog::ORDER_CONFIRMATION )->update( [ 'is_active' => false ] );

    expect( $request->shouldSend( $customer, 'mail' ) )->toBeFalse()
        ->and( $placed->shouldSend( $customer, 'mail' ) )->toBeFalse();
} );

it( 'emails guest orders at the order address', function (): void {
    Notification::fake();
    $order = Order::factory()->guest()->create( [ 'email' => 'guest@example.test' ] );

    doAction( 'ap.ecommerce.order.placed', $order );

    Notification::assertSentOnDemand( EcommerceNotification::class, fn ( EcommerceNotification $n, array $channels, AnonymousNotifiable $notifiable ): bool => 'guest@example.test' === $notifiable->routeNotificationFor( 'mail' ) );
} );

it( 'alerts staff about payments, stock, and reviews awaiting moderation', function (): void {
    Notification::fake();
    $order   = customerOrder();
    $product = Product::factory()->create( [ 'name' => 'Poster', 'sku' => 'P-1' ] );
    $item    = InventoryItem::factory()->create( [ 'stockable_type' => $product->getMorphClass(), 'stockable_id' => $product->id, 'low_stock_threshold' => 5 ] );

    doAction( 'ap.ecommerce.payment.succeeded', null, $order );
    doAction( 'ap.ecommerce.inventory.lowStock', $item, 3 );
    doAction( 'ap.ecommerce.inventory.outOfStock', $item );
    app( ReviewService::class )->submit( $product, [ 'rating' => 5, 'author_name' => 'Ada' ] );
    app( ReviewService::class )->submit( $product, [ 'rating' => 5 ], null, true );

    expect( staffTemplates() )->toBe( [
        NotificationCatalog::ORDER_PAID_ADMIN,
        NotificationCatalog::LOW_STOCK_ADMIN,
        NotificationCatalog::OUT_OF_STOCK_ADMIN,
        NotificationCatalog::REVIEW_AWAITING_MODERATION_ADMIN,
    ] );

    Notification::assertSentOnDemand( EcommerceNotification::class, fn ( EcommerceNotification $n ): bool => NotificationCatalog::LOW_STOCK_ADMIN !== $n->templateKey
        || ( 'Poster' === $n->variables['InventoryItem']['name'] && 3 === $n->variables['InventoryItem']['on_hand'] ) );
} );

it( 'sends nothing to staff when no admin address is configured', function (): void {
    Notification::fake();
    config()->set( 'artisanpack.ecommerce.notifications.admin_emails', [] );

    doAction( 'ap.ecommerce.payment.succeeded', null, customerOrder() );

    Notification::assertNothingSent();
} );

it( 'uses the store owner\'s edited copy on the next send', function (): void {
    Notification::fake();
    $service = app( NotificationTemplateService::class );
    $service->sync();

    $template = NotificationTemplate::query()->where( 'key', NotificationCatalog::ORDER_CONFIRMATION )->sole();
    $service->update( $template, [ 'subject' => 'Thanks {{ Order.customer.first_name }}! Order #{{ Order.number }}' ] );

    $order = customerOrder();
    doAction( 'ap.ecommerce.order.placed', $order );

    Notification::assertSentTo( $order->customer, EcommerceNotification::class, fn ( EcommerceNotification $n ): bool => 'Thanks Ada! Order #K7QM2XW9' === $n->toMail( $order->customer )->subjectLine );
} );

it( 'does not send switched-off templates', function (): void {
    Notification::fake();
    app( NotificationTemplateService::class )->sync();
    NotificationTemplate::query()->where( 'key', NotificationCatalog::ORDER_CONFIRMATION )->update( [ 'is_active' => false ] );

    doAction( 'ap.ecommerce.order.placed', customerOrder() );

    Notification::assertNothingSent();
} );

it( 'honours customer preferences except for transactional mail', function (): void {
    Notification::fake();
    $customer = Customer::factory()->create();
    app( NotificationPreferenceService::class )->update( $customer, [
        [ 'channel' => 'mail', 'category' => 'review-requests', 'is_enabled' => false ],
        [ 'channel' => 'mail', 'category' => 'shipping-updates', 'is_enabled' => false ],
        [ 'channel' => 'mail', 'category' => 'transactional', 'is_enabled' => false ],
    ] );
    $order = customerOrder( $customer );

    doAction( 'ap.ecommerce.order.placed', $order );
    doAction( 'ap.ecommerce.order.delivered', $order, Shipment::factory()->create( [ 'order_id' => $order->id, 'status' => Shipment::STATUS_DELIVERED ] ) );

    expect( sentTemplates( $customer ) )->toBe( [ NotificationCatalog::ORDER_CONFIRMATION ] )
        ->and( CustomerNotificationPreference::query()->where( 'category', 'transactional' )->sole()->is_enabled )->toBeFalse();
} );

it( 'defaults marketing to the customer\'s marketing consent', function (): void {
    $preferences = app( NotificationPreferenceService::class );
    $consenting  = Customer::factory()->create( [ 'accepts_marketing' => true ] );
    $silent      = Customer::factory()->create( [ 'accepts_marketing' => false ] );

    expect( $preferences->allows( $consenting, 'mail', 'marketing' ) )->toBeTrue()
        ->and( $preferences->allows( $silent, 'mail', 'marketing' ) )->toBeFalse()
        ->and( $preferences->allows( $silent, 'mail', 'review-requests' ) )->toBeTrue();

    $preferences->update( $silent, [ [ 'channel' => 'mail', 'category' => 'marketing', 'is_enabled' => true ] ] );

    expect( $preferences->allows( $silent, 'mail', 'marketing' ) )->toBeTrue();
} );

it( 'filters template variables and fires the sending hooks on delivery', function (): void {
    config()->set( 'mail.default', 'array' );
    $sending = [];
    $sent    = [];
    addFilter( 'ap.ecommerce.notification.templateVariables', function ( array $vars, string $key, mixed $subject ): array {
        $vars['Order']['number'] = 'FILTERED-' . ( $subject instanceof Order ? $subject->id : 'x' );

        return $vars;
    } );
    addAction( 'ap.ecommerce.notification.sending', function ( EcommerceNotification $n, mixed $notifiable, string $channel ) use ( &$sending ): void {
        $sending[] = $n->templateKey . ':' . $channel;
    } );
    addAction( 'ap.ecommerce.notification.sent', function ( EcommerceNotification $n ) use ( &$sent ): void {
        $sent[] = $n->templateKey;
    } );

    $order = customerOrder();
    app( NotificationDispatcher::class )->send( NotificationCatalog::ORDER_CANCELLED, $order->customer, [ 'Order' => [ 'number' => 'K7' ] ], $order );

    $messages = Mail::mailer( 'array' )->getSymfonyTransport()->messages();

    expect( $messages )->toHaveCount( 1 )
        ->and( $messages[0]->getOriginalMessage()->getSubject() )->toBe( 'Your order FILTERED-' . $order->id . ' was cancelled' )
        ->and( $messages[0]->getOriginalMessage()->getTo()[0]->getAddress() )->toBe( $order->customer->email );

    expect( $sending )->toBe( [ NotificationCatalog::ORDER_CANCELLED . ':mail' ] )
        ->and( $sent )->toBe( [ NotificationCatalog::ORDER_CANCELLED ] );
} );

it( 'encrypts queued notifications, whose context can hold links and keys', function (): void {
    expect( new EcommerceNotification( NotificationCatalog::DOWNLOAD_READY, 'mail', [] ) )
        ->toBeInstanceOf( Illuminate\Contracts\Queue\ShouldBeEncrypted::class );
} );

it( 'ignores unknown template keys', function (): void {
    Notification::fake();

    expect( app( NotificationDispatcher::class )->send( 'nope.nothing', Customer::factory()->create(), [] ) )->toBe( 0 );

    Notification::assertNothingSent();
} );

it( 'syncs a default row for every catalog entry, keeping edits', function (): void {
    $service = app( NotificationTemplateService::class );
    $service->sync();

    $row = NotificationTemplate::query()->where( 'key', NotificationCatalog::ORDER_SHIPPED )->sole();
    $row->forceFill( [ 'subject' => 'Custom', 'variables' => [] ] )->save();

    $service->sync();

    expect( NotificationTemplate::query()->count() )->toBe( count( NotificationCatalog::definitions() ) )
        ->and( $row->fresh()->subject )->toBe( 'Custom' )
        ->and( $row->fresh()->variables )->toContain( 'Shipment.tracking_url' );
} );

it( 'does not ask staff to moderate reviews that are already decided', function (): void {
    Notification::fake();
    $review = ProductReview::factory()->approved()->create();

    doAction( 'ap.ecommerce.review.submitted', $review );

    Notification::assertNothingSent();
} );
