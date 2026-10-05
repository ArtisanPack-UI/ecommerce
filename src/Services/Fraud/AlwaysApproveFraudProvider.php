<?php

/**
 * AlwaysApproveFraudProvider.
 *
 * Reference {@see \ArtisanPackUI\Ecommerce\Contracts\FraudProvider}
 * implementation that unconditionally approves every assessment. Used by
 * stores that do not want fraud gating — the assess step is still invoked
 * so the timeline entry, `orders.meta.fraud_decision`, and hook signals
 * remain uniform across configurations (engine plan §6.1 / §8.4).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services\Fraud;

use ArtisanPackUI\Ecommerce\Contracts\FraudProvider;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\FraudDecision;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class AlwaysApproveFraudProvider implements FraudProvider
{
    /**
     * @since 1.0.0
     */
    public const KEY = 'always-approve';

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Always approve (fraud gating disabled)' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart            $cart
     * @param  Address         $shipping
     * @param  PaymentSession  $session
     *
     * @return FraudDecision
     */
    public function assess( Cart $cart, Address $shipping, PaymentSession $session ): FraudDecision
    {
        return FraudDecision::approve( 0, [ 'always_approve' ] );
    }
}
