<?php

/**
 * IssueDigitalDeliverables.
 *
 * On `ap.ecommerce.payment.succeeded`, issues download entitlements for
 * every digital file on the paid order and license keys for every line
 * whose product has licensing enabled, then emails the customer their
 * links and keys (`digital.download-ready.customer`). Issuing is
 * idempotent per order line, so a replayed payment webhook doesn't
 * double-issue. Toggle with `artisanpack.ecommerce.digital.auto_issue`.
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

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Notifications\NotificationContext;
use ArtisanPackUI\Ecommerce\Notifications\NotificationDispatcher;
use ArtisanPackUI\Ecommerce\Services\DigitalDownloadService;
use ArtisanPackUI\Ecommerce\Services\LicenseService;
use Illuminate\Support\Facades\DB;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class IssueDigitalDeliverables
{
    /**
     * @since 1.0.0
     *
     * @param  DigitalDownloadService  $downloads      Download entitlements.
     * @param  LicenseService          $licenses       License keys.
     * @param  NotificationDispatcher  $notifications  Notification dispatcher.
     * @param  NotificationContext     $context        Context builder.
     */
    public function __construct(
        protected DigitalDownloadService $downloads,
        protected LicenseService $licenses,
        protected NotificationDispatcher $notifications,
        protected NotificationContext $context,
    ) {
    }

    /**
     * `ap.ecommerce.payment.succeeded` listener.
     *
     * @since 1.0.0
     *
     * @param  mixed  $payment  Payment result.
     * @param  Order  $order    Paid order.
     *
     * @return void
     */
    public function handle( mixed $payment, Order $order ): void
    {
        $this->issue( $order );
    }

    /**
     * Issues whatever `$order` is still owed and emails it.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Paid order.
     *
     * @return void
     */
    public function issue( Order $order ): void
    {
        // Lock the order so two concurrent payment.succeeded deliveries (a
        // webhook racing the redirect) can't both issue.
        [ $downloads, $licenses ] = DB::transaction( function () use ( $order ): array {
            Order::query()->lockForUpdate()->find( $order->id );

            return [ $this->downloads->issueForOrder( $order ), $this->licenses->issueForOrder( $order ) ];
        } );

        if ( [] === $downloads && [] === $licenses ) {
            return;
        }

        $this->notifications->send( NotificationCatalog::DOWNLOAD_READY, $this->notifications->orderRecipients( $order ), [
            'Order'     => $this->context->order( $order ),
            'Downloads' => $this->context->downloads( $downloads ),
            'Licenses'  => $this->context->licenses( $licenses ),
        ], $order );
    }
}
