<?php

/**
 * FraudBlocked event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator::finalize()}
 * when the active {@see \ArtisanPackUI\Ecommerce\Contracts\FraudProvider}
 * returns a `block` verdict — after the pending authorization has been
 * voided and the order has been marked `failed`. Engine spec §7 event #16.
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
use ArtisanPackUI\Ecommerce\ValueObjects\FraudDecision;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class FraudBlocked
{
    /**
     * @since 1.0.0
     *
     * @param  Order          $order     The refreshed order after being marked failed.
     * @param  FraudDecision  $decision  The block decision.
     */
    public function __construct(
        public readonly Order $order,
        public readonly FraudDecision $decision,
    ) {
    }
}
