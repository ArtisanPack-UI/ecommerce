<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\DigitalProductUpdated;
use ArtisanPackUI\Ecommerce\Listeners\SendCatalogNotifications;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Notifications\EcommerceNotification;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.notifications.admin_emails', [ 'ops@example.test' ] );
    Notification::fake();
} );

afterEach( function (): void {
    removeAllActions( 'ap.ecommerce.notification.sending' );
    removeAllActions( 'ap.ecommerce.notification.sent' );
} );

function covCatalogListener(): SendCatalogNotifications
{
    return app( SendCatalogNotifications::class );
}

/**
 * Template keys of every catalog notification sent, in order.
 *
 * @return array<int, string>
 */
function covSentTemplates(): array
{
    $keys = [];

    foreach ( Notification::sentNotifications() as $byClass ) {
        foreach ( $byClass as $notifications ) {
            foreach ( $notifications as $byId ) {
                foreach ( $byId as $record ) {
                    if ( $record['notification'] instanceof EcommerceNotification ) {
                        $keys[] = $record['notification']->templateKey;
                    }
                }
            }
        }
    }

    return $keys;
}

it( 'tells staff about low and out-of-stock items with the level', function (): void {
    $item = InventoryItem::factory()->create( [ 'quantity_on_hand' => 2, 'low_stock_threshold' => 5 ] );

    covCatalogListener()->lowStock( $item, 2 );
    covCatalogListener()->outOfStock( $item );

    Notification::assertSentOnDemand( EcommerceNotification::class, fn ( EcommerceNotification $n, array $channels, AnonymousNotifiable $notifiable ): bool => NotificationCatalog::LOW_STOCK_ADMIN === $n->templateKey
        && 2 === $n->variables['InventoryItem']['on_hand']
        && [ 'ops@example.test' ] === (array) $notifiable->routeNotificationFor( 'mail' ) );
    Notification::assertSentOnDemand( EcommerceNotification::class, fn ( EcommerceNotification $n ): bool => NotificationCatalog::OUT_OF_STOCK_ADMIN === $n->templateKey
        && 0 === $n->variables['InventoryItem']['on_hand'] );
} );

it( 'sends nothing to staff when no admin address is configured', function (): void {
    config()->set( 'artisanpack.ecommerce.notifications.admin_emails', [] );

    covCatalogListener()->outOfStock( InventoryItem::factory()->create() );

    Notification::assertNothingSent();
} );

it( 'asks staff to moderate only reviews still pending', function (): void {
    $pending  = ProductReview::factory()->create( [ 'status' => ProductReview::STATUS_PENDING ] );
    $approved = ProductReview::factory()->create( [ 'status' => ProductReview::STATUS_APPROVED ] );

    covCatalogListener()->reviewSubmitted( $approved );
    Notification::assertNothingSent();

    covCatalogListener()->reviewSubmitted( $pending );
    expect( covSentTemplates() )->toBe( [ NotificationCatalog::REVIEW_AWAITING_MODERATION_ADMIN ] );
} );

it( 'tells each buyer of an updated file once', function (): void {
    $product = Product::factory()->create();
    $file    = DigitalFile::factory()->create( [ 'product_id' => $product->id, 'version' => '2.0' ] );
    $ada     = Customer::factory()->create();

    // Ada bought it twice; a guest bought it once; someone else bought another file.
    foreach ( [ Order::factory()->forCustomer( $ada )->create(), Order::factory()->forCustomer( $ada )->create(), Order::factory()->create( [ 'customer_id' => null, 'email' => 'guest@example.test' ] ) ] as $order ) {
        DigitalDownload::factory()->create( [ 'order_item_id' => OrderItem::factory()->create( [ 'order_id' => $order->id ] )->id, 'digital_file_id' => $file->id ] );
    }

    DigitalDownload::factory()->create( [ 'order_item_id' => OrderItem::factory()->create( [ 'order_id' => Order::factory()->create()->id ] )->id ] );

    covCatalogListener()->digitalProductUpdated( new DigitalProductUpdated( $product, $file ) );

    expect( Notification::sent( $ada, EcommerceNotification::class ) )->toHaveCount( 1 )
        ->and( Notification::sent( $ada, EcommerceNotification::class )->first()->templateKey )->toBe( NotificationCatalog::DIGITAL_PRODUCT_UPDATED )
        ->and( covSentTemplates() )->toBe( [ NotificationCatalog::DIGITAL_PRODUCT_UPDATED, NotificationCatalog::DIGITAL_PRODUCT_UPDATED ] );
} );

it( 'tells the customer about a cancellation but not other status changes', function (): void {
    $order = Order::factory()->forCustomer( Customer::factory()->create() )->create();

    covCatalogListener()->orderStatusChanged( $order, 'pending', 'processing' );
    Notification::assertNothingSent();

    covCatalogListener()->orderStatusChanged( $order, 'processing', 'cancelled' );
    expect( Notification::sent( $order->customer, EcommerceNotification::class )->first()->templateKey )->toBe( NotificationCatalog::ORDER_CANCELLED );
} );

it( 'waits for the last shipment before announcing delivery', function (): void {
    $order = Order::factory()->forCustomer( Customer::factory()->create() )->create();
    $done  = Shipment::factory()->create( [ 'order_id' => $order->id, 'status' => Shipment::STATUS_DELIVERED ] );
    Shipment::factory()->create( [ 'order_id' => $order->id, 'status' => Shipment::STATUS_IN_TRANSIT ] );

    covCatalogListener()->orderDelivered( $order, $done );

    Notification::assertNothingSent();
} );

it( 'sends a review request straight away when the delay is zero', function (): void {
    config()->set( 'artisanpack.ecommerce.notifications.review_request_delay_days', 0 );
    $order = Order::factory()->forCustomer( Customer::factory()->create() )->create();

    covCatalogListener()->orderDelivered( $order );

    $request = Notification::sent( $order->customer, EcommerceNotification::class )->first( fn ( EcommerceNotification $n ): bool => NotificationCatalog::REVIEW_REQUEST === $n->templateKey );

    expect( $request )->not->toBeNull()
        ->and( $request->delay )->toBeNull();
} );

it( 're-fires sending and sent hooks for catalog notifications only', function (): void {
    $calls = [];
    addAction( 'ap.ecommerce.notification.sending', function ( EcommerceNotification $n, mixed $notifiable, string $channel ) use ( &$calls ): void {
        $calls[] = 'sending:' . $channel;
    } );
    addAction( 'ap.ecommerce.notification.sent', function ( EcommerceNotification $n, mixed $notifiable, string $channel ) use ( &$calls ): void {
        $calls[] = 'sent:' . $channel;
    } );

    $ours   = new EcommerceNotification( NotificationCatalog::ORDER_CONFIRMATION, 'mail', [] );
    $theirs = new class extends BaseNotification {
    };
    $to     = Notification::route( 'mail', 'ada@example.test' );

    covCatalogListener()->sending( new NotificationSending( $to, $ours, 'mail' ) );
    covCatalogListener()->sent( new NotificationSent( $to, $ours, 'mail' ) );
    covCatalogListener()->sending( new NotificationSending( $to, $theirs, 'mail' ) );
    covCatalogListener()->sent( new NotificationSent( $to, $theirs, 'mail' ) );

    expect( $calls )->toBe( [ 'sending:mail', 'sent:mail' ] );
} );
