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
use ArtisanPackUI\Ecommerce\Contracts\RendersClientPayment;
use ArtisanPackUI\Ecommerce\Exceptions\PaymentCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Money\Currency;
use Money\Money;
use RuntimeException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class StripeGateway implements PaymentGateway, RendersClientPayment
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

        $returnUrl = isset( $context[ 'return_url' ] ) ? (string) $context[ 'return_url' ] : '';

        // Stripe requires a `return_url` when confirming a PaymentIntent that
        // accepts redirect-based payment methods (iDEAL, Klarna, Bancontact, …).
        // Callers that cannot supply one must not have their checkout fail at
        // confirmation — filter redirect-based methods out of automatic payment
        // methods when no return URL is available. Engine spec §4.2.
        $automaticPaymentMethods = [ 'enabled' => true ];

        if ( '' === $returnUrl ) {
            $automaticPaymentMethods[ 'allow_redirects' ] = 'never';
        }

        $params = [
            'amount'                    => $this->toStripeAmount( $amount ),
            'currency'                  => strtolower( $currency ),
            'automatic_payment_methods' => $automaticPaymentMethods,
            'metadata'                  => array_merge(
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

        if ( '' !== $returnUrl ) {
            $params[ 'return_url' ] = $returnUrl;
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

        return $this->sessionFromIntent( $intent );
    }

    /**
     * Loads a PaymentIntent and reports it as a {@see PaymentSession} with
     * its current amount, currency, and normalized status.
     *
     * @since 1.0.0
     *
     * @param  string  $reference  PaymentIntent id.
     *
     * @throws ApiErrorException When Stripe refuses the request or can't be reached.
     *
     * @return PaymentSession
     */
    public function retrievePaymentSession( string $reference ): PaymentSession
    {
        return $this->sessionFromIntent( $this->client()->paymentIntents->retrieve( $reference ) );
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
                    $this->receivedAmount( $intent, $session ),
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
                        $this->receivedAmount( $intent, $session ),
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
     * A no-op when the order has no PaymentIntent, when Stripe reports it
     * already `canceled`, or when it no longer exists — so a second void is
     * harmless (engine issue #154). Throws when the intent was captured
     * (refund it instead), when Stripe refuses or can't be reached, and
     * when the cancel response doesn't confirm `canceled`, so the engine
     * never records a live authorization as voided. The fraud path treats
     * the void as best-effort and catches these.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  The order whose pending authorization is being released.
     *
     * @throws ApiErrorException When Stripe refuses the request or can't be reached.
     * @throws RuntimeException  When the intent was captured, or the cancellation is unconfirmed.
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
        } catch ( InvalidRequestException $e ) {
            if ( 'resource_missing' === $e->getStripeCode() ) {
                return;
            }

            throw $e;
        }

        if ( 'canceled' === $intent->status ) {
            return;
        }

        if ( 'succeeded' === $intent->status ) {
            throw new RuntimeException( __( 'Payment :reference was already captured; refund it instead of voiding it.', [ 'reference' => $reference ] ) );
        }

        $canceled = $this->client()->paymentIntents->cancel(
            $reference,
            [],
            $this->requestOptions( [ 'idempotency_key' => 'ap-ec-void-' . $reference ] ),
        );

        if ( 'canceled' !== $canceled->status ) {
            throw new RuntimeException( __( 'Stripe did not confirm that payment :reference was voided (status: :status).', [ 'reference' => $reference, 'status' => (string) $canceled->status ] ) );
        }
    }

    /**
     * Refunds `$amount` against `$order` via Stripe's Refund API.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order    The order the refund is being issued against.
     * @param  Money                 $amount   Amount to refund, in the order's payment currency.
     * @param  string|null           $reason   Optional free-text reason forwarded to Stripe.
     * @param  array<string, mixed>  $context  `idempotency_key` and `refund_id` (see the contract).
     *
     * @throws PaymentCurrencyMismatchException When `$amount` is not in `$order->currency`.
     *
     * @return RefundResult
     */
    public function refund( Order $order, Money $amount, ?string $reason = null, array $context = [] ): RefundResult
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
            'metadata'       => array_filter( [
                'ap_ec_order_id'  => (string) $order->getKey(),
                'ap_ec_refund_id' => isset( $context['refund_id'] ) ? (string) $context['refund_id'] : null,
            ], static fn ( ?string $value ): bool => null !== $value ),
        ];

        $mappedReason = $this->mapRefundReason( $reason );

        if ( null !== $mappedReason ) {
            $params[ 'reason' ] = $mappedReason;
        }

        // The engine passes a key per refund row, so a retry of the same
        // refund never moves money twice. Without one, derive a key from the
        // refunds already recorded for the order (a retry of a failed
        // attempt sees the same count and reuses it).
        $idempotencyKey = isset( $context['idempotency_key'] ) && '' !== (string) $context['idempotency_key']
            ? (string) $context['idempotency_key']
            : sprintf(
                'ap-ec-refund-%s-%d-%s',
                $reference,
                Refund::query()->where( 'order_id', $order->getKey() )->count() + 1,
                $amount->getAmount(),
            );

        try {
            $refund = $this->client()->refunds->create(
                $params,
                $this->requestOptions( [ 'idempotency_key' => $idempotencyKey ] ),
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
        $secrets = $this->webhookSecrets();

        if ( [] === $secrets ) {
            return WebhookResult::unverified( 'webhook_secret_not_configured', 'Stripe webhook secret is not configured.' );
        }

        $signature = (string) $request->header( self::SIGNATURE_HEADER, '' );

        if ( '' === $signature ) {
            return WebhookResult::unverified( 'signature_missing', 'Stripe-Signature header is missing.' );
        }

        // Several secrets are accepted at once so an endpoint secret can be
        // rotated without dropping deliveries signed with the old one.
        $event = null;
        $error = null;

        foreach ( $secrets as $secret ) {
            try {
                $event = $this->verifier->verify( $request->getContent(), $signature, $secret );

                break;
            } catch ( SignatureVerificationException $e ) {
                $error = $e;
            }
        }

        if ( null === $event ) {
            return WebhookResult::unverified( 'signature_mismatch', $error?->getMessage() );
        }

        $payload = $event->toArray();
        $type    = (string) $event->type;
        $object  = (array) ( $payload['data']['object'] ?? [] );

        [ $outcome, $reference, $refundReference ] = $this->webhookOutcome( $type, $object );

        return WebhookResult::verified( $type, (string) $event->id, $payload, $outcome, $reference, $refundReference );
    }

    /**
     * Describes the client-side step for `$session`: Stripe's Payment
     * Element, with the publishable key and the PaymentIntent's client
     * secret (both meant to reach the browser; card data never touches the
     * server).
     *
     * @since 1.0.0
     *
     * @param  Cart            $cart     Cart being paid for.
     * @param  PaymentSession  $session  Session from createPaymentSession().
     *
     * @return array{driver: string, flow: string, publishable_key: string, client_secret: string|null, redirect_url: null, options: array<string, mixed>}
     */
    public function clientConfig( Cart $cart, PaymentSession $session ): array
    {
        return [
            'driver'          => 'stripe-payment-element',
            'flow'            => 'embedded',
            'publishable_key' => (string) $this->config->get( 'artisanpack.ecommerce.gateways.stripe.publishable_key', '' ),
            'client_secret'   => $session->clientSecret,
            'redirect_url'    => null,
            'options'         => [
                'locale'     => str_replace( '_', '-', (string) app()->getLocale() ),
                'appearance' => (array) $this->config->get( 'artisanpack.ecommerce.gateways.stripe.appearance', [] ),
            ],
        ];
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
     * Builds a {@see PaymentSession} from a PaymentIntent.
     *
     * @since 1.0.0
     *
     * @param  PaymentIntent  $intent  The intent.
     *
     * @return PaymentSession
     */
    private function sessionFromIntent( PaymentIntent $intent ): PaymentSession
    {
        $currency = strtoupper( (string) ( $intent->currency ?? 'usd' ) );

        return new PaymentSession(
            gatewayKey: $this->key(),
            reference: (string) $intent->id,
            amount: new Money( (int) ( $intent->amount ?? 0 ), new Currency( $currency ) ),
            clientSecret: $intent->client_secret ?? null,
            metadata: [
                'publishable_key' => (string) $this->config->get( 'artisanpack.ecommerce.gateways.stripe.publishable_key', '' ),
                'status'          => (string) ( $intent->status ?? '' ),
                'charge'          => $this->latestChargeId( $intent ),
            ],
            status: $this->normalizeStatus( (string) ( $intent->status ?? '' ) ),
        );
    }

    /**
     * Maps a PaymentIntent status onto {@see PaymentSession}'s statuses.
     *
     * @since 1.0.0
     *
     * @param  string  $status  Stripe status.
     *
     * @return string|null
     */
    private function normalizeStatus( string $status ): ?string
    {
        return match ( $status ) {
            'requires_payment_method', 'requires_confirmation' => PaymentSession::STATUS_REQUIRES_PAYMENT_METHOD,
            'requires_action'                                  => PaymentSession::STATUS_REQUIRES_ACTION,
            'processing'                                       => PaymentSession::STATUS_PROCESSING,
            'requires_capture'                                 => PaymentSession::STATUS_AUTHORIZED,
            'succeeded'                                        => PaymentSession::STATUS_SUCCEEDED,
            'canceled'                                         => PaymentSession::STATUS_CANCELED,
            default                                            => null,
        };
    }

    /**
     * What Stripe actually captured on a succeeded PaymentIntent
     * (`amount_received` in its own currency), falling back to the session
     * amount when Stripe doesn't report it.
     *
     * @since 1.0.0
     *
     * @param  PaymentIntent   $intent   Captured intent.
     * @param  PaymentSession  $session  Session being captured.
     *
     * @return Money
     */
    private function receivedAmount( PaymentIntent $intent, PaymentSession $session ): Money
    {
        if ( ! isset( $intent->amount_received ) ) {
            return $session->amount;
        }

        $currency = strtoupper( (string) ( $intent->currency ?? $session->amount->getCurrency()->getCode() ) );

        return new Money( (int) $intent->amount_received, new Currency( $currency ) );
    }

    /**
     * The configured webhook secrets: `webhook_secret` may be one secret, a
     * comma-separated list, or an array (to rotate without downtime).
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    private function webhookSecrets(): array
    {
        $configured = $this->config->get( 'artisanpack.ecommerce.gateways.stripe.webhook_secret', '' );
        $secrets    = is_array( $configured ) ? $configured : explode( ',', (string) $configured );

        return array_values( array_filter( array_map( static fn ( mixed $secret ): string => trim( (string) $secret ), $secrets ), static fn ( string $secret ): bool => '' !== $secret ) );
    }

    /**
     * The normalized outcome of a Stripe event: `[outcome, session reference, refund id]`.
     *
     * @since 1.0.0
     *
     * @param  string                $type    Event type.
     * @param  array<string, mixed>  $object  `data.object`.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function webhookOutcome( string $type, array $object ): array
    {
        $intentId = static fn ( mixed $value ): ?string => is_string( $value ) && '' !== $value ? $value : ( is_array( $value ) && isset( $value['id'] ) ? (string) $value['id'] : null );

        return match ( $type ) {
            // Captured, or (manual capture) authorized and ready to capture.
            'payment_intent.succeeded',
            'payment_intent.amount_capturable_updated' => [ WebhookResult::OUTCOME_SUCCEEDED, $intentId( $object['id'] ?? null ), null ],
            'payment_intent.payment_failed',
            'payment_intent.canceled'                  => [ WebhookResult::OUTCOME_FAILED, $intentId( $object['id'] ?? null ), null ],
            'payment_intent.requires_action'           => [ WebhookResult::OUTCOME_REQUIRES_ACTION, $intentId( $object['id'] ?? null ), null ],
            'charge.refunded'                          => [ WebhookResult::OUTCOME_REFUNDED, $intentId( $object['payment_intent'] ?? null ), null ],
            'refund.created', 'refund.updated'         => 'succeeded' === ( $object['status'] ?? null )
                ? [ WebhookResult::OUTCOME_REFUNDED, $intentId( $object['payment_intent'] ?? null ), isset( $object['id'] ) ? (string) $object['id'] : null ]
                : [ null, null, null ],
            default                                    => [ null, null, null ],
        };
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
