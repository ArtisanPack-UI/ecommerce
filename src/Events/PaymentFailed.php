<?php

/**
 * PaymentFailed event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator::finalize()}
 * on any capture failure — either the gateway returned a
 * {@see \ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult} with
 * `$success === false` or it threw. The order may be `null` in the
 * rare case a failure surfaces before the order has been persisted.
 * Engine spec §7 event #14.
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

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Models\Order;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PaymentFailed
{
    /**
     * @since 1.0.0
     *
     * @param  Order|null      $order    The order the capture failed against, if one was persisted.
     * @param  PaymentGateway  $gateway  The gateway the failure surfaced from.
     * @param  Throwable       $reason   The captured throwable — either a real exception or a synthetic one wrapping the gateway's error code/message.
     */
    public function __construct(
        public readonly ?Order $order,
        public readonly PaymentGateway $gateway,
        public readonly Throwable $reason,
    ) {
    }
}
