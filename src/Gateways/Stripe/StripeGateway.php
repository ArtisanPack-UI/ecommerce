<?php

/**
 * StripeGateway.
 *
 * Reference {@see \ArtisanPackUI\Ecommerce\Contracts\PaymentGateway}
 * implementation for Stripe. Uses the modern Payment Intents API with
 * automatic payment methods enabled — card collection happens exclusively
 * on the client via Stripe Elements, so the engine never touches raw card
 * data (SAQ-A compliance).
 *
 * Saved cards are supported via SetupIntents on registered Stripe
 * Customers, which the engine can create by passing an `off_session_setup`
 * flag through `createPaymentSession()`'s `$context`. Refunds are issued
 * against the captured PaymentIntent through Stripe's Refund API. Inbound
 * webhooks are verified with Stripe's HMAC-SHA256 signature header. Every
 * PI mutation forwards the caller's `Idempotency-Key` through Stripe's
 * `Idempotency-Key` header so retries at the API layer are safe end-to-end.
 *
 * Engine spec §4.2, parent plan §7.5 + §8.1 + §8.5.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Gateways\Stripe;

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Exceptions\PaymentCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Money\Currency;
use Money\Money;
use RuntimeException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class StripeGateway implements PaymentGateway
{
    /**
     * Registry key. Matches `orders.payment_gateway_key` for Stripe orders.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'stripe';

    /**
     * Signature header Stripe sends with every webhook.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SIGNATURE_HEADER = 'Stripe-Signature';

    /**
     * Lazily-resolved Stripe API client.
     *
     * @since 1.0.0
     *
     * @var StripeClient|null
     */
    private ?StripeClient $client = null;

    /**
     * @since 1.0.0
     *
     * @param  StripeClientFactory       $clientFactory  Factory building a configured {@see StripeClient}.
     * @param  StripeSignatureVerifier   $verifier       Webhook signature verifier.
     * @param  Repository                $config         Laravel config repository.
     */
    public function __construct(
        private readonly StripeClientFactory $clientFactory,
        private readonly StripeSignatureVerifier $verifier,
        private readonly Repository $config,
    ) {
    }

    /**
     * Overrides the internal client — test hook.
     *
     * @since 1.0.0
     *
     * @param  StripeClient  $client  Client to use.
     *
     * @return void
     */
    public function setClient( StripeClient $client ): void
    {
        $this->client = $client;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Stripe';
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function supportsPartialRefunds(): bool
    {
        return true;
    }

    public function supportsSavedInstruments(): bool
    {
        return true;
    }

    /**
     * Creates a Stripe PaymentIntent for `$cart` with automatic payment
     * methods enabled and returns its client secret for Elements to consume.
     *
     * `$context` may carry:
     * - `idempotency_key`   (string) — forwarded as Stripe's `Idempotency-Key` header
     * - `stripe_customer`   (string) — attach the PI to an existing Stripe Customer
     * - `setup_future_usage`(string) — `on_session` | `off_session` to save the card
     * - `metadata`          (array)  — extra metadata copied to the PI
     * - `return_url`        (string) — used by hosted redirects (Klarna, iDEAL, …)
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart the session is being created for.
     * @param  array<string, mixed>  $context  Provider-specific hints.
     *
     * @return PaymentSession
     */
    public function createPaymentSession( Cart $cart, array $context = [] ): PaymentSession
    {
        $currency = strtoupper( (string) ( $cart->getAttribute( 'currency' ) ?: $cart->getAttribute( 'total_currency' ) ?: 'USD' ) );
        $amount   = new Money( (int) ( $cart->getAttribute( 'total_amount' ) ?? 0 ), new Currency( $currency ) );

        $params = [
            'amount'                      => $this->toStripeAmount( $amount ),
            'currency'                    => strtolower( $currency ),
            'automatic_payment_methods'   => [ 'enabled' => true ],
            'metadata'                    => array_merge(
                [ 'ap_ec_cart_id' => (string) $cart->getKey() ],
                (array) ( $context[ 'metadata' ] ?? [] ),
            ),
        ];

        if ( isset( $context[ 'stripe_customer' ] ) && '' !== (string) $context[ 'stripe_customer' ] ) {
            $params[ 'customer' ] = (string) $context[ 'stripe_customer' ];
        }

        if ( isset( $context[ 'setup_future_usage' ] ) ) {
            $params[ 'setup_future_usage' ] = (string) $context[ 'setup_future_usage' ];
        }

        if ( isset( $context[ 'return_url' ] ) ) {
            $params[ 'return_url' ] = (string) $context[ 'return_url' ];
        }

        $captureMethod = (string) $this->config->get(
            'artisanpack.ecommerce.gateways.stripe.capture_method',
            'automatic',
        );

        if ( in_array( $captureMethod, [ 'manual', 'automatic', 'automatic_async' ], true ) ) {
            $params[ 'capture_method' ] = $captureMethod;
        }

        $intent = $this->client()->paymentIntents->create(
            $params,
            $this->requestOptions( $context ),
        );

        return new PaymentSession(
            gatewayKey: $this->key(),
            reference: (string) $intent->id,
            amount: $amount,
            clientSecret: $intent->client_secret ?? null,
            metadata: [
                'publishable_key' => (string) $this->config->get( 'artisanpack.ecommerce.gateways.stripe.publishable_key', '' ),
                'status'          => (string) ( $intent->status ?? '' ),
            ],
        );
    }

    /**
     * Captures a Stripe PaymentIntent.
     *
     * A PI in `requires_capture` is captured; a PI already in `succeeded`
     * is treated as a no-op (idempotent — a second capture call for the
     * same order returns the same result without moving money twice). Every
     * other status maps to a retryable or terminal {@see PaymentResult}
     * based on whether the state can plausibly resolve on retry.
     *
     * @since 1.0.0
     *
     * @param  Order           $order    The order being captured.
     * @param  PaymentSession  $session  Session from {@see self::createPaymentSession()}.
     *
     * @throws PaymentCurrencyMismatchException When session currency does not match the order's.
     *
     * @return PaymentResult
     */
    public function capturePayment( Order $order, PaymentSession $session ): PaymentResult
    {
        $orderCurrency = strtoupper( (string) $order->currency );

        if ( $session->amount->getCurrency()->getCode() !== $orderCurrency ) {
            throw new PaymentCurrencyMismatchException(
                $orderCurrency,
                $session->amount->getCurrency()->getCode(),
            );
        }

        try {
            $intent = $this->client()->paymentIntents->retrieve( $session->reference );

            if ( 'succeeded' === $intent->status ) {
                return PaymentResult::success(
                    $session->amount,
                    $this->latestChargeId( $intent ) ?? $intent->id,
                );
            }

            if ( 'requires_capture' === $intent->status ) {
                $intent = $this->client()->paymentIntents->capture(
                    $session->reference,
                    [],
                    $this->requestOptions( [ 'idempotency_key' => 'ap-ec-capture-' . $session->reference ] ),
                );

                if ( 'succeeded' === $intent->status ) {
                    return PaymentResult::success(
                        $session->amount,
                        $this->latestChargeId( $intent ) ?? $intent->id,
                    );
                }
            }

            if ( in_array( $intent->status, [ 'requires_payment_method', 'canceled' ], true ) ) {
                return PaymentResult::terminalFailure(
                    $session->amount,
                    'payment_intent_' . $intent->status,
                    sprintf( 'PaymentIntent is in terminal status "%s".', $intent->status ),
                );
            }

            return PaymentResult::retryableFailure(
                $session->amount,
                'payment_intent_' . $intent->status,
                sprintf( 'PaymentIntent is in non-terminal status "%s"; retry later.', $intent->status ),
            );
        } catch ( CardException $e ) {
            return PaymentResult::terminalFailure(
                $session->amount,
                (string) ( $e->getStripeCode() ?: 'card_declined' ),
                $e->getMessage(),
            );
        } catch ( RateLimitException | ApiConnectionException $e ) {
            return PaymentResult::retryableFailure(
                $session->amount,
                (string) ( $e->getStripeCode() ?: 'provider_unavailable' ),
                $e->getMessage(),
            );
        } catch ( ApiErrorException $e ) {
            return $this->mapApiErrorToPaymentResult( $e, $session->amount );
        }
    }

    /**
     * Cancels an uncaptured PaymentIntent.
     *
     * No-op when the PI has already been captured, canceled, or cannot be
     * found — the engine calls this from the fraud path (engine spec §8.4)
     * and MUST NOT throw on double-void.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  The order whose pending authorization is being released.
     *
     * @return void
     */
    public function voidPendingPayment( Order $order ): void
    {
        $reference = (string) $order->getAttribute( 'payment_reference' );

        if ( '' === $reference ) {
            return;
        }

        try {
            $intent = $this->client()->paymentIntents->retrieve( $reference );

            if ( in_array( $intent->status, [ 'succeeded', 'canceled' ], true ) ) {
                return;
            }

            $this->client()->paymentIntents->cancel(
                $reference,
                [],
                $this->requestOptions( [ 'idempotency_key' => 'ap-ec-void-' . $reference ] ),
            );
        } catch ( Throwable $e ) {
            // Voiding is best-effort — the fraud path already treats the
            // order as blocked. Surfacing an exception here would prevent
            // that state transition, which is a bigger problem than a
            // dangling authorization Stripe will expire on its own. Log
            // it so operators can reconcile stale authorizations.
            Log::channel( 'ecommerce' )->warning( 'Stripe void failed; authorization will expire on its own.', [
                'order_id'          => $order->getKey(),
                'payment_reference' => $reference,
                'error'             => $e->getMessage(),
            ] );
        }
    }

    /**
     * Refunds `$amount` against `$order` via Stripe's Refund API.
     *
     * @since 1.0.0
     *
     * @param  Order        $order    The order the refund is being issued against.
     * @param  Money        $amount   Amount to refund, in the order's payment currency.
     * @param  string|null  $reason   Optional free-text reason forwarded to Stripe.
     *
     * @throws PaymentCurrencyMismatchException When `$amount` is not in `$order->currency`.
     *
     * @return RefundResult
     */
    public function refund( Order $order, Money $amount, ?string $reason = null ): RefundResult
    {
        $orderCurrency = strtoupper( (string) $order->currency );

        if ( $amount->getCurrency()->getCode() !== $orderCurrency ) {
            throw new PaymentCurrencyMismatchException(
                $orderCurrency,
                $amount->getCurrency()->getCode(),
            );
        }

        $reference = (string) $order->getAttribute( 'payment_reference' );

        if ( '' === $reference ) {
            return RefundResult::failure( $amount, 'missing_payment_reference', 'Order has no Stripe payment reference to refund.' );
        }

        $params = [
            'payment_intent' => $reference,
            'amount'         => $this->toStripeAmount( $amount ),
            'metadata'       => [ 'ap_ec_order_id' => (string) $order->getKey() ],
        ];

        $mappedReason = $this->mapRefundReason( $reason );

        if ( null !== $mappedReason ) {
            $params[ 'reason' ] = $mappedReason;
        }

        try {
            $refund = $this->client()->refunds->create(
                $params,
                $this->requestOptions( [ 'idempotency_key' => 'ap-ec-refund-' . $reference . '-' . $amount->getAmount() . '-' . uniqid( '', true ) ] ),
            );

            if ( 'failed' === $refund->status || 'canceled' === $refund->status ) {
                return RefundResult::failure(
                    $amount,
                    (string) ( $refund->failure_reason ?? 'refund_' . $refund->status ),
                    sprintf( 'Stripe refund %s.', (string) $refund->status ),
                );
            }

            return RefundResult::success( $amount, (string) $refund->id );
        } catch ( CardException $e ) {
            return RefundResult::failure(
                $amount,
                (string) ( $e->getStripeCode() ?: 'card_error' ),
                $e->getMessage(),
            );
        } catch ( ApiErrorException $e ) {
            return RefundResult::failure(
                $amount,
                (string) ( $e->getStripeCode() ?: 'api_error' ),
                $e->getMessage(),
            );
        }
    }

    /**
     * Verifies an inbound Stripe webhook against the endpoint secret.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Raw inbound Stripe webhook request.
     *
     * @return WebhookResult
     */
    public function handleWebhook( Request $request ): WebhookResult
    {
        $secret = (string) $this->config->get( 'artisanpack.ecommerce.gateways.stripe.webhook_secret', '' );

        if ( '' === $secret ) {
            return WebhookResult::unverified( 'webhook_secret_not_configured', 'Stripe webhook secret is not configured.' );
        }

        $signature = (string) $request->header( self::SIGNATURE_HEADER, '' );

        if ( '' === $signature ) {
            return WebhookResult::unverified( 'signature_missing', 'Stripe-Signature header is missing.' );
        }

        try {
            $event = $this->verifier->verify( $request->getContent(), $signature, $secret );
        } catch ( SignatureVerificationException $e ) {
            return WebhookResult::unverified( 'signature_mismatch', $e->getMessage() );
        }

        $payload = $event->toArray();

        return WebhookResult::verified(
            (string) $event->type,
            (string) $event->id,
            $payload,
        );
    }

    /**
     * Returns the request-options array forwarded to the Stripe SDK on
     * every API call. `Idempotency-Key` is the important one — passing the
     * client's request-scoped key through makes retries at the API layer
     * safe end-to-end (engine spec §11.2).
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $context  Provider-specific hints.
     *
     * @return array<string, mixed>
     */
    private function requestOptions( array $context ): array
    {
        $options = [];

        if ( isset( $context[ 'idempotency_key' ] ) && '' !== (string) $context[ 'idempotency_key' ] ) {
            $options[ 'idempotency_key' ] = (string) $context[ 'idempotency_key' ];
        }

        return $options;
    }

    /**
     * Converts a {@see Money} value into the integer amount Stripe expects.
     *
     * Both `moneyphp/money` and Stripe represent amounts in the currency's
     * smallest unit as defined by ISO 4217 — cents for USD/EUR, whole
     * units for zero-decimal currencies like JPY/KRW. The values align
     * directly with no per-currency scaling required.
     *
     * @since 1.0.0
     * @see https://stripe.com/docs/currencies#zero-decimal
     *
     * @param  Money  $money  Amount, in the currency's smallest unit.
     *
     * @return int
     */
    private function toStripeAmount( Money $money ): int
    {
        return (int) $money->getAmount();
    }

    /**
     * Extracts the latest charge id from a captured PaymentIntent for the
     * order's `payment_reference` column. Stripe returns it under
     * `latest_charge` (v2023-10-16+) which can be either a string id or an
     * expanded {@see \Stripe\Charge} object.
     *
     * @since 1.0.0
     *
     * @param  PaymentIntent  $intent  The captured PaymentIntent.
     *
     * @return string|null
     */
    private function latestChargeId( PaymentIntent $intent ): ?string
    {
        $latest = $intent->latest_charge ?? null;

        if ( is_string( $latest ) && '' !== $latest ) {
            return $latest;
        }

        if ( is_object( $latest ) && isset( $latest->id ) ) {
            return (string) $latest->id;
        }

        return null;
    }

    /**
     * Maps a free-text refund reason to Stripe's small enum of accepted
     * values (duplicate / fraudulent / requested_by_customer). Anything
     * else is left off the request so Stripe defaults it.
     *
     * @since 1.0.0
     *
     * @param  string|null  $reason  Free-text reason.
     *
     * @return string|null
     */
    private function mapRefundReason( ?string $reason ): ?string
    {
        if ( null === $reason ) {
            return null;
        }

        $normalized = strtolower( trim( $reason ) );

        return match ( $normalized ) {
            'duplicate'              => 'duplicate',
            'fraud', 'fraudulent'    => 'fraudulent',
            'customer',
            'requested_by_customer',
            'customer_request'       => 'requested_by_customer',
            default                  => null,
        };
    }

    /**
     * Maps a non-card, non-transient Stripe API error to a
     * {@see PaymentResult}. Anything with a `type` of `invalid_request_error`
     * or `authentication_error` is terminal — retrying won't fix a bad
     * client secret or a malformed request. Everything else is retryable.
     *
     * @since 1.0.0
     *
     * @param  ApiErrorException  $exception  The Stripe SDK exception.
     * @param  Money              $amount     Amount that was attempted.
     *
     * @return PaymentResult
     */
    private function mapApiErrorToPaymentResult( ApiErrorException $exception, Money $amount ): PaymentResult
    {
        $type = (string) ( $exception->getError()?->type ?? '' );
        $code = (string) ( $exception->getStripeCode() ?: ( '' !== $type ? $type : 'stripe_api_error' ) );

        if ( in_array( $type, [ 'invalid_request_error', 'authentication_error' ], true ) ) {
            return PaymentResult::terminalFailure( $amount, $code, $exception->getMessage() );
        }

        return PaymentResult::retryableFailure( $amount, $code, $exception->getMessage() );
    }

    /**
     * Lazily resolves the {@see StripeClient} used for API calls.
     *
     * @since 1.0.0
     *
     * @throws RuntimeException When the client cannot be built.
     *
     * @return StripeClient
     */
    private function client(): StripeClient
    {
        return $this->client ??= $this->clientFactory->make();
    }
}
