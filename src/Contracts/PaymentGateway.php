<?php

/**
 * PaymentGateway contract.
 *
 * Adapter for a payment provider (Stripe, PayPal, etc.). The engine calls
 * into this contract from checkout, capture, refund, and webhook flows —
 * Phase 1 wires the refund seam only (issue #13); later phases add
 * session/capture/webhook methods per engine spec §4.2.
 *
 * Implementations register themselves under a unique {@see self::key()}
 * value via {@see \ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry}
 * so the service layer can resolve the gateway an {@see Order} was placed
 * through via its `payment_gateway_key` column.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use Money\Money;

/**
 * PaymentGateway contract.
 *
 * The Phase 1 surface is intentionally small — only the members
 * {@see \ArtisanPackUI\Ecommerce\Services\RefundService} needs. Session,
 * capture, void, and webhook members will be added in later phases per
 * engine spec §4.2; downstream implementations pin against the interface
 * version they were built for.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface PaymentGateway
{
    /**
     * Machine-readable gateway key (e.g. `stripe`, `paypal`, `manual`).
     *
     * Matches the value stored on `orders.payment_gateway_key` for orders
     * placed through this gateway.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string;

    /**
     * Whether this gateway supports issuing refunds at all.
     *
     * A gateway that returns `false` here rejects every refund attempt —
     * useful for cash / offline payment providers that reconcile refunds
     * out-of-band.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function supportsRefunds(): bool;

    /**
     * Whether this gateway supports refunds smaller than the captured total.
     *
     * A gateway that returns `false` here accepts only full refunds; the
     * service layer rejects any partial refund request routed to such a
     * gateway with a {@see \ArtisanPackUI\Ecommerce\Exceptions\RefundNotAllowedException}.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function supportsPartialRefunds(): bool;

    /**
     * Issues a refund of `$amount` against `$order`.
     *
     * `$amount` MUST be in the order's payment currency ({@see Order::$currency}).
     * Implementations are responsible for calling the provider API and
     * translating success / failure into a {@see RefundResult} — they MUST
     * NOT throw for a provider-declined refund; return a `RefundResult`
     * whose {@see RefundResult::$success} is `false` instead so the ledger
     * row is not written but the timeline captures the attempt.
     *
     * @since 1.0.0
     *
     * @param  Order        $order    The order the refund is being issued against.
     * @param  Money        $amount   Amount to refund, in the order's payment currency.
     * @param  string|null  $reason   Optional free-text reason forwarded to the provider.
     *
     * @return RefundResult
     */
    public function refund( Order $order, Money $amount, ?string $reason = null ): RefundResult;
}
