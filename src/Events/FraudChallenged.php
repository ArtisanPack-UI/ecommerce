<?php

/**
 * FraudChallenged event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator::finalize()}
 * when the active {@see \ArtisanPackUI\Ecommerce\Contracts\FraudProvider}
 * returns a `challenge` verdict — the customer must complete a step-up
 * (typically 3DS) before capture can proceed. Carries the still-pending
 * cart and the authorized {@see PaymentSession} whose `clientSecret` or
 * `redirectUrl` the storefront hands to the customer as the step-up
 * token. Engine spec §7 event #17.
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

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ValueObjects\FraudDecision;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class FraudChallenged
{
    /**
     * @since 1.0.0
     *
     * @param  Cart            $cart      The cart still pending capture.
     * @param  FraudDecision   $decision  The challenge decision.
     * @param  PaymentSession  $session   The authorized (money-held) session carrying the step-up token.
     */
    public function __construct(
        public readonly Cart $cart,
        public readonly FraudDecision $decision,
        public readonly PaymentSession $session,
    ) {
    }
}
