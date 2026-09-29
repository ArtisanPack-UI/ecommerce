<?php

/**
 * RevokeDigitalDeliverables.
 *
 * When an order is fully refunded (`ap.ecommerce.order.refunded` with the
 * order's `payment_status` now `refunded`) or cancelled
 * (`ap.ecommerce.order.statusChanged` → `cancelled`), expires the order's
 * download entitlements and revokes its license keys, so "buy, download,
 * refund" doesn't leave working links and keys behind. Partial refunds keep
 * access. Toggle with `artisanpack.ecommerce.digital.revoke_on_refund`.
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

use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Services\LicenseService;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RevokeDigitalDeliverables
{
    /**
     * @since 1.0.0
     *
     * @param  LicenseService  $licenses  License lifecycle.
     */
    public function __construct( protected LicenseService $licenses )
    {
    }

    /**
     * `ap.ecommerce.order.refunded` listener.
     *
     * @since 1.0.0
     *
     * @param  Order  $order   Refunded order.
     * @param  mixed  $refund  The refund.
     *
     * @return void
     */
    public function refunded( Order $order, mixed $refund = null ): void
    {
        if ( 'refunded' === $order->fresh()?->payment_status ) {
            $this->revoke( $order, __( 'Order refunded' ) );
        }
    }

    /**
     * `ap.ecommerce.order.statusChanged` listener.
     *
     * @since 1.0.0
     *
     * @param  Order   $order  Order.
     * @param  string  $from   Previous status.
     * @param  string  $to     New status.
     *
     * @return void
     */
    public function statusChanged( Order $order, string $from, string $to ): void
    {
        if ( 'cancelled' === $to ) {
            $this->revoke( $order, __( 'Order cancelled' ) );
        }
    }

    /**
     * Expires every download and revokes every key issued for `$order`.
     *
     * @since 1.0.0
     *
     * @param  Order   $order   Order.
     * @param  string  $reason  Revocation reason.
     *
     * @return void
     */
    public function revoke( Order $order, string $reason ): void
    {
        $items = $order->items()->pluck( 'id' );

        DigitalDownload::query()
            ->whereIn( 'order_item_id', $items )
            ->where( fn ( $query ) => $query->whereNull( 'expires_at' )->orWhere( 'expires_at', '>', now() ) )
            ->update( [ 'expires_at' => now()->subSecond(), 'updated_at' => now() ] );

        LicenseKey::query()
            ->whereIn( 'order_item_id', $items )
            ->where( 'is_revoked', false )
            ->get()
            ->each( fn ( LicenseKey $key ) => $this->licenses->revoke( $key, $reason ) );
    }
}
