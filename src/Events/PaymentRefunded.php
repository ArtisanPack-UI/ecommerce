<?php

/**
 * PaymentRefunded event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\RefundService::issue()}
 * with the raw {@see RefundResult} the gateway returned. Distinct from
 * {@see OrderRefunded}, which carries the persisted ledger row: listeners
 * that care about the provider-side outcome (reconciliation, retry
 * bookkeeping, provider-specific metadata) key off this one instead.
 *
 * Engine spec §7 event #15.
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
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PaymentRefunded
{
    /**
     * @since 1.0.0
     *
     * @param  Order         $order   The refreshed order after the refund.
     * @param  RefundResult  $result  Raw gateway outcome.
     */
    public function __construct(
        public readonly Order $order,
        public readonly RefundResult $result,
    ) {
    }
}
