<?php

/**
 * PaymentGateway contract.
 *
 * Adapter for a payment provider (Stripe, PayPal, etc.). The engine calls
 * into this contract from checkout, capture, refund, void, and webhook
 * flows per engine spec §4.2. Implementations register themselves under a
 * unique {@see self::key()} value via {@see \ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry}
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

use ArtisanPackUI\Ecommerce\Exceptions\PaymentCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Http\Request;
use Money\Money;

/**
 * PaymentGateway contract.
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
     * Human-readable label displayed to admins in the payment method picker.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string;

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
     * Whether this gateway supports customer-saved payment instruments
     * (Stripe SetupIntents, PayPal vaulting, etc.) for one-click checkout.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function supportsSavedInstruments(): bool;

    /**
     * Creates a provider-side payment session for `$cart` (Stripe PaymentIntent,
     * PayPal Order, etc.). The engine passes the current request's
     * `Idempotency-Key` through `$context` so retries at the API layer are
     * safe end-to-end (engine spec §11.2).
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart the session is being created for.
     * @param  array<string, mixed>  $context  Provider-specific hints (return URLs, idempotency key, etc.).
     *
     * @return PaymentSession
     */
    public function createPaymentSession( Cart $cart, array $context = [] ): PaymentSession;

    /**
     * Captures a previously-authorized payment identified by `$session`.
     *
     * Implementations MUST be idempotent — a second capture call for the
     * same `$session` MUST return a {@see PaymentResult} equivalent to the
     * first without moving money a second time. Provider-declined captures
     * MUST return a `PaymentResult` (retryable or terminal) rather than
     * throwing, so the timeline records the attempt.
     *
     * @since 1.0.0
     *
     * @param  Order           $order    The order being captured.
     * @param  PaymentSession  $session  The session {@see self::createPaymentSession()} produced at checkout.
     *
     * @throws PaymentCurrencyMismatchException When `$session->amount` is not in `$order->currency`.
     *
     * @return PaymentResult
     */
    public function capturePayment( Order $order, PaymentSession $session ): PaymentResult;

    /**
     * Voids an authorization that has not yet been captured.
     *
     * Called by the fraud path (engine spec §8.4) when an order is blocked
     * before capture. Implementations MUST NOT throw for a session that has
     * already been voided or is not voidable — treat the call as a no-op.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  The order whose pending authorization is being released.
     *
     * @return void
     */
    public function voidPendingPayment( Order $order ): void;

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
     * @throws PaymentCurrencyMismatchException When `$amount` is not in `$order->currency`.
     *
     * @return RefundResult
     */
    public function refund( Order $order, Money $amount, ?string $reason = null ): RefundResult;

    /**
     * Handles a signed inbound provider webhook.
     *
     * Implementations MUST verify the request signature against the shared
     * secret before returning a verified {@see WebhookResult}; a request
     * that does not verify MUST return {@see WebhookResult::unverified()}
     * so the controller layer responds `400` without dispatching any
     * downstream events.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Raw inbound provider webhook request.
     *
     * @return WebhookResult
     */
    public function handleWebhook( Request $request ): WebhookResult;
}
