<?php

/**
 * OrderRefunded event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\RefundService::issue()}
 * after a refund has been recorded on the order and any restock has been
 * applied. Carries the refreshed {@see Order} plus the persisted
 * {@see Refund} ledger row. Engine spec §7 event #11.
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
use ArtisanPackUI\Ecommerce\Models\Refund;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderRefunded
{
    /**
     * @since 1.0.0
     *
     * @param  Order   $order   The refreshed order after the refund.
     * @param  Refund  $refund  The refund ledger row that was persisted.
     */
    public function __construct(
        public readonly Order $order,
        public readonly Refund $refund,
    ) {
    }
}
