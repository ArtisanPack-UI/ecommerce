<?php

/**
 * SendCatalogNotifications.
 *
 * Connects the engine's lifecycle hooks and events to the notification
 * catalog (parent plan §14.4):
 *
 * | Trigger                                              | Template                               | To        |
 * |------------------------------------------------------|----------------------------------------|-----------|
 * | `ap.ecommerce.order.placed`                          | `order.confirmation.customer`          | customer  |
 * | `ap.ecommerce.payment.succeeded`                     | `order.paid.admin`                     | staff     |
 * | `ap.ecommerce.order.shipped`                         | `order.shipped.customer`               | customer  |
 * | `ap.ecommerce.order.delivered`                       | `order.delivered.customer`, then `review.request.customer` (delayed) | customer |
 * | `ap.ecommerce.order.statusChanged` → `cancelled`     | `order.cancelled.customer`             | customer  |
 * | `ap.ecommerce.order.refunded`                        | `order.refunded.customer`              | customer  |
 * | {@see DigitalProductUpdated}                         | `digital.product-updated.customer`     | buyers    |
 * | `ap.ecommerce.license.activated`                     | `license.activated.customer`           | customer  |
 * | `ap.ecommerce.inventory.lowStock` / `.outOfStock`    | `inventory.low-stock.admin` / `inventory.out-of-stock.admin` | staff |
 * | `ap.ecommerce.review.submitted` (still pending)      | `review.awaiting-moderation.admin`     | staff     |
 *
 * `digital.download-ready.customer` is sent by {@see IssueDigitalDeliverables},
 * which has the freshly issued links. Laravel's `NotificationSending` /
 * `NotificationSent` events for catalog notifications are re-fired as
 * `ap.ecommerce.notification.sending` / `.sent` (engine spec §6.15).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Listeners;

use ArtisanPackUI\Ecommerce\Events\DigitalProductUpdated;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\LicenseActivation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Notifications\EcommerceNotification;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Notifications\NotificationContext;
use ArtisanPackUI\Ecommerce\Notifications\NotificationDispatcher;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SendCatalogNotifications
{
    /**
     * @since 1.0.0
     *
     * @param  NotificationDispatcher  $notifications  Dispatcher.
     * @param  NotificationContext     $context        Context builder.
     */
    public function __construct(
        protected NotificationDispatcher $notifications,
        protected NotificationContext $context,
    ) {
    }

    /**
     * Wires the listeners.
     *
     * @since 1.0.0
     *
     * @param  Dispatcher  $events  Event dispatcher.
     *
     * @return void
     */
    public function subscribe( Dispatcher $events ): void
    {
        addAction( 'ap.ecommerce.order.placed', [ $this, 'orderPlaced' ] );
        addAction( 'ap.ecommerce.payment.succeeded', [ $this, 'paymentSucceeded' ] );
        addAction( 'ap.ecommerce.order.shipped', [ $this, 'orderShipped' ] );
        addAction( 'ap.ecommerce.order.delivered', [ $this, 'orderDelivered' ] );
        addAction( 'ap.ecommerce.order.statusChanged', [ $this, 'orderStatusChanged' ] );
        addAction( 'ap.ecommerce.order.refunded', [ $this, 'orderRefunded' ] );
        addAction( 'ap.ecommerce.license.activated', [ $this, 'licenseActivated' ] );
        addAction( 'ap.ecommerce.inventory.lowStock', [ $this, 'lowStock' ] );
        addAction( 'ap.ecommerce.inventory.outOfStock', [ $this, 'outOfStock' ] );
        addAction( 'ap.ecommerce.review.submitted', [ $this, 'reviewSubmitted' ] );

        $events->listen( DigitalProductUpdated::class, [ $this, 'digitalProductUpdated' ] );
        $events->listen( NotificationSending::class, [ $this, 'sending' ] );
        $events->listen( NotificationSent::class, [ $this, 'sent' ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order  $order  Placed order.
     *
     * @return void
     */
    public function orderPlaced( Order $order ): void
    {
        $this->toCustomer( NotificationCatalog::ORDER_CONFIRMATION, $order );
    }

    /**
     * @since 1.0.0
     *
     * @param  mixed  $payment  Payment result.
     * @param  Order  $order    Paid order.
     *
     * @return void
     */
    public function paymentSucceeded( mixed $payment, Order $order ): void
    {
        $this->notifications->send( NotificationCatalog::ORDER_PAID_ADMIN, $this->notifications->adminRecipients(), [ 'Order' => $this->context->order( $order ) ], $order );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order     $order     Order.
     * @param  Shipment  $shipment  New shipment.
     *
     * @return void
     */
    public function orderShipped( Order $order, Shipment $shipment ): void
    {
        $this->toCustomer( NotificationCatalog::ORDER_SHIPPED, $order, [ 'Shipment' => $this->context->shipment( $shipment ) ] );
    }

    /**
     * Sends the delivery notice, then queues the review request — once
     * every shipment on the order is delivered.
     *
     * @since 1.0.0
     *
     * @param  Order          $order     Order.
     * @param  Shipment|null  $shipment  Delivered shipment.
     *
     * @return void
     */
    public function orderDelivered( Order $order, ?Shipment $shipment = null ): void
    {
        // The hook fires per delivered shipment; tell the customer once, when
        // the last of the order's shipments arrives.
        if ( $order->shipments()->where( 'status', '!=', Shipment::STATUS_DELIVERED )->exists() ) {
            return;
        }

        $this->toCustomer( NotificationCatalog::ORDER_DELIVERED, $order, null === $shipment ? [] : [ 'Shipment' => $this->context->shipment( $shipment ) ] );

        $days = (int) config( 'artisanpack.ecommerce.notifications.review_request_delay_days', 7 );

        $this->toCustomer( NotificationCatalog::REVIEW_REQUEST, $order, [], $days > 0 ? now()->addDays( $days ) : null );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order   $order  Order.
     * @param  string  $from   Previous status.
     * @param  string  $to     New status.
     *
     * @return void
     */
    public function orderStatusChanged( Order $order, string $from, string $to ): void
    {
        if ( 'cancelled' === $to ) {
            $this->toCustomer( NotificationCatalog::ORDER_CANCELLED, $order );
        }
    }

    /**
     * @since 1.0.0
     *
     * @param  Order   $order   Order.
     * @param  Refund  $refund  Refund.
     *
     * @return void
     */
    public function orderRefunded( Order $order, Refund $refund ): void
    {
        $this->toCustomer( NotificationCatalog::ORDER_REFUNDED, $order, [ 'Refund' => $this->context->refund( $refund ) ] );
    }

    /**
     * Tells everyone holding a download of the file about the new version.
     *
     * @since 1.0.0
     *
     * @param  DigitalProductUpdated  $event  Event.
     *
     * @return void
     */
    public function digitalProductUpdated( DigitalProductUpdated $event ): void
    {
        $orders = Order::query()
            ->whereIn( 'id', DigitalDownload::query()
                ->where( 'digital_file_id', $event->file->id )
                ->join( 'order_items', 'order_items.id', '=', 'digital_downloads.order_item_id' )
                ->select( 'order_items.order_id' ) )
            ->with( 'customer' )
            ->get()
            ->unique( fn ( Order $order ): string => null !== $order->customer_id ? 'c' . $order->customer_id : 'e' . strtolower( (string) $order->email ) );

        foreach ( $orders as $order ) {
            $this->notifications->send( NotificationCatalog::DIGITAL_PRODUCT_UPDATED, $this->notifications->orderRecipients( $order ), [
                'Product'  => $this->context->product( $event->product ),
                'File'     => $this->context->file( $event->file ),
                'Customer' => $this->context->customer( $order->customer, $order->email, (array) ( $order->billing_address ?? [] ) ),
            ], $event->file );
        }
    }

    /**
     * @since 1.0.0
     *
     * @param  LicenseActivation  $activation  New activation.
     *
     * @return void
     */
    public function licenseActivated( LicenseActivation $activation ): void
    {
        $license = $activation->licenseKey()->with( 'orderItem.order.customer', 'orderItem.product' )->first();
        $order   = $license?->orderItem?->order;

        if ( null === $order ) {
            return;
        }

        $this->notifications->send( NotificationCatalog::LICENSE_ACTIVATED, $this->notifications->orderRecipients( $order ), [
            'Product'    => [ 'name' => $license->orderItem->product_snapshot['name'] ?? $license->orderItem->product?->name, 'sku' => $license->orderItem->product_snapshot['sku'] ?? null ],
            'License'    => $this->context->license( $license ),
            'Activation' => $this->context->activation( $activation ),
            'Customer'   => $this->context->customer( $order->customer, $order->email, (array) ( $order->billing_address ?? [] ) ),
        ], $activation );
    }

    /**
     * @since 1.0.0
     *
     * @param  InventoryItem  $item   Inventory row.
     * @param  int            $level  New on-hand level.
     *
     * @return void
     */
    public function lowStock( InventoryItem $item, int $level ): void
    {
        $this->toStaff( NotificationCatalog::LOW_STOCK_ADMIN, $item, $level );
    }

    /**
     * @since 1.0.0
     *
     * @param  InventoryItem  $item  Inventory row.
     *
     * @return void
     */
    public function outOfStock( InventoryItem $item ): void
    {
        $this->toStaff( NotificationCatalog::OUT_OF_STOCK_ADMIN, $item, 0 );
    }

    /**
     * Tells staff about a review that is waiting for them.
     *
     * @since 1.0.0
     *
     * @param  ProductReview  $review  Submitted review.
     *
     * @return void
     */
    public function reviewSubmitted( ProductReview $review ): void
    {
        if ( ProductReview::STATUS_PENDING !== $review->status ) {
            return;
        }

        $this->notifications->send( NotificationCatalog::REVIEW_AWAITING_MODERATION_ADMIN, $this->notifications->adminRecipients(), [
            'Product' => $this->context->product( $review->product ),
            'Review'  => $this->context->review( $review ),
        ], $review );
    }

    /**
     * `NotificationSending` → `ap.ecommerce.notification.sending`.
     *
     * @since 1.0.0
     *
     * @param  NotificationSending  $event  Event.
     *
     * @return void
     */
    public function sending( NotificationSending $event ): void
    {
        if ( $event->notification instanceof EcommerceNotification ) {
            doAction( 'ap.ecommerce.notification.sending', $event->notification, $event->notifiable, $event->channel );
        }
    }

    /**
     * `NotificationSent` → `ap.ecommerce.notification.sent`.
     *
     * @since 1.0.0
     *
     * @param  NotificationSent  $event  Event.
     *
     * @return void
     */
    public function sent( NotificationSent $event ): void
    {
        if ( $event->notification instanceof EcommerceNotification ) {
            doAction( 'ap.ecommerce.notification.sent', $event->notification, $event->notifiable, $event->channel );
        }
    }

    /**
     * Sends an order notification to the order's customer.
     *
     * @since 1.0.0
     *
     * @param  string                         $templateKey  Catalog key.
     * @param  Order                          $order        Order.
     * @param  array<string, mixed>           $variables    Extra context.
     * @param  DateTimeInterface|null        $delay        Send no earlier than this.
     *
     * @return void
     */
    protected function toCustomer( string $templateKey, Order $order, array $variables = [], ?DateTimeInterface $delay = null ): void
    {
        $this->notifications->send( $templateKey, $this->notifications->orderRecipients( $order ), [ 'Order' => $this->context->order( $order ) ] + $variables, $order, $delay );
    }

    /**
     * Sends a stock alert to staff.
     *
     * @since 1.0.0
     *
     * @param  string         $templateKey  Catalog key.
     * @param  InventoryItem  $item         Inventory row.
     * @param  int            $level        On-hand level.
     *
     * @return void
     */
    protected function toStaff( string $templateKey, InventoryItem $item, int $level ): void
    {
        $this->notifications->send( $templateKey, $this->notifications->adminRecipients(), [
            'Product'       => $this->context->product( $this->context->productForInventory( $item ) ),
            'InventoryItem' => $this->context->inventoryItem( $item, $level ),
        ], $item );
    }
}
