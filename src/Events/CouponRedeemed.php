<?php

/**
 * CouponRedeemed event.
 *
 * Dispatched at order placement for the coupon the order was priced with,
 * once its usage is recorded and the placement has committed. Engine spec
 * §7 event #31.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Events;

use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CouponRedeemed implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  Coupon  $coupon  The redeemed coupon.
     * @param  Order   $order   The order it was redeemed on.
     * @param  Money   $amount  Discount its promotion gave the order.
     */
    public function __construct(
        public readonly Coupon $coupon,
        public readonly Order $order,
        public readonly Money $amount,
    ) {
    }
}
