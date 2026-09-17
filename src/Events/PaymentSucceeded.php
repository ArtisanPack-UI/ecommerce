<?php

/**
 * PaymentSucceeded event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator::finalize()}
 * after a successful `capturePayment()` call. Engine spec §7 event #13.
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

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PaymentSucceeded
{
    /**
     * @since 1.0.0
     *
     * @param  Order          $order    The refreshed order after capture.
     * @param  PaymentResult  $payment  The successful capture result.
     */
    public function __construct(
        public readonly Order $order,
        public readonly PaymentResult $payment,
    ) {
    }
}
