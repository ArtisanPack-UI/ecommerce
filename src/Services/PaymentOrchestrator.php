<?php

/**
 * PaymentOrchestrator.
 *
 * Owns the 3-step checkout finalize sequence — authorize → assess → capture
 * — so every {@see PaymentGateway} follows the same security-critical
 * choreography. Baking the sequence into individual gateways would
 * duplicate the fraud gate and let one gateway drift; centralising it here
 * means a new provider only implements the {@see PaymentGateway} contract.
 * Engine plan §8.4, spec §7 events #13/#14/#16/#17.
 *
 * The three terminal outcomes are:
 *
 * - **Captured** — fraud provider approved, gateway captured the
 *   authorization, `orders.payment_status` → `paid`.
 * - **Challenged** — fraud provider needs a step-up (3DS/etc.). The
 *   authorization stays open, the order stays `pending`, and the caller
 *   surfaces the session's client secret / redirect URL to the customer
 *   as the step-up token.
 * - **Blocked** — fraud provider blocked. The authorization is voided,
 *   the order is marked `failed`, and the block reasons are persisted to
 *   `orders.meta.fraud_decision` (never surfaced to the customer).
 *
 * Idempotency per engine §7.5: repeat calls against an order that has
 * already reached a terminal state return the recorded outcome without
 * re-charging money or re-firing events.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Contracts\FraudProvider;
use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Events\FraudBlocked;
use ArtisanPackUI\Ecommerce\Events\FraudChallenged;
use ArtisanPackUI\Ecommerce\Events\PaymentFailed;
use ArtisanPackUI\Ecommerce\Events\PaymentSucceeded;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Registries\FraudProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Services\Fraud\ChainFraudProvider;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\Currency as CurrencyVO;
use ArtisanPackUI\Ecommerce\ValueObjects\FraudDecision;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentFinalization;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Money\Money;
use RuntimeException;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PaymentOrchestrator
{
    /**
     * @since 1.0.0
     *
     * @param  PaymentGatewayRegistry  $gateways
     * @param  FraudProviderRegistry   $fraudProviders
     * @param  Config                  $config
     */
    public function __construct(
        protected PaymentGatewayRegistry $gateways,
        protected FraudProviderRegistry $fraudProviders,
        protected Config $config,
    ) {
    }

    /**
     * Runs the authorize → assess → capture sequence against `$order`.
     *
     * `$order` must carry a `payment_gateway_key` pointing at a registered
     * gateway; `$cart` is the source cart the order was placed from,
     * needed for the authorize step (`createPaymentSession`) and for the
     * fraud-provider signature. `$context` is forwarded to the gateway
     * verbatim — the caller passes the request idempotency key through
     * here so provider-side retries are safe (engine spec §11.2).
     *
     * @since 1.0.0
     *
     * @param  Order                 $order    Order to finalize; must already be persisted with `payment_gateway_key` set.
     * @param  Cart                  $cart     Cart the order was placed from.
     * @param  Address               $shipping Shipping destination the fraud provider will assess against.
     * @param  array<string, mixed>  $context  Provider-specific hints forwarded to `createPaymentSession()`.
     *
     * @throws RuntimeException When required infrastructure is missing (gateway or fraud provider not registered).
     *
     * @return PaymentFinalization
     */
    public function finalize(
        Order $order,
        Cart $cart,
        Address $shipping,
        array $context = [],
    ): PaymentFinalization {
        $gateway       = $this->resolveGateway( $order );
        $fraudProvider = $this->resolveFraudProvider();

        // Idempotency (§7.5): a repeat finalize on an order already in a
        // terminal state must not re-charge money or re-fire events.
        $existing = $this->recoverTerminalOutcome( $order, $gateway );
        if ( null !== $existing ) {
            return $existing;
        }

        $session = $this->authorize( $order, $cart, $gateway, $context );

        $decision = $this->assess( $cart, $shipping, $session, $fraudProvider );

        if ( $decision->isBlock() ) {
            return $this->handleBlock( $order, $gateway, $session, $decision );
        }

        if ( $decision->isChallenge() ) {
            return $this->handleChallenge( $order, $cart, $session, $decision );
        }

        return $this->capture( $order, $gateway, $session, $decision );
    }

    /**
     * Resolves the {@see PaymentGateway} keyed on `$order->payment_gateway_key`.
     *
     * @since 1.0.0
     *
     * @param  Order  $order
     *
     * @throws RuntimeException When the order has no gateway key or the gateway is not registered.
     *
     * @return PaymentGateway
     */
    protected function resolveGateway( Order $order ): PaymentGateway
    {
        $key = (string) ( $order->payment_gateway_key ?? '' );

        if ( '' === $key ) {
            throw new RuntimeException( sprintf(
                'Order %d has no payment_gateway_key set; cannot resolve a gateway to finalize through.',
                $order->id,
            ) );
        }

        if ( ! $this->gateways->has( $key ) ) {
            throw new RuntimeException( sprintf(
                'PaymentGateway "%s" for order %d is not registered.',
                $key,
                $order->id,
            ) );
        }

        return $this->gateways->get( $key );
    }

    /**
     * Resolves the active {@see FraudProvider} from configuration.
     *
     * @since 1.0.0
     *
     * @throws RuntimeException When the configured provider is not registered.
     *
     * @return FraudProvider
     */
    protected function resolveFraudProvider(): FraudProvider
    {
        $raw = trim( (string) $this->config->get( 'artisanpack.ecommerce.fraud.provider', '' ) );

        if ( '' === $raw ) {
            throw new RuntimeException(
                'No FraudProvider configured; set artisanpack.ecommerce.fraud.provider to a registered provider key.',
            );
        }

        $keys = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), static fn ( string $k ): bool => '' !== $k ) );

        if ( [] === $keys ) {
            throw new RuntimeException(
                'artisanpack.ecommerce.fraud.provider is set but contains no usable provider keys.',
            );
        }

        $providers = [];

        foreach ( $keys as $key ) {
            if ( ! $this->fraudProviders->has( $key ) ) {
                throw new RuntimeException( sprintf(
                    'FraudProvider "%s" is configured but not registered.',
                    $key,
                ) );
            }

            $providers[] = $this->fraudProviders->get( $key );
        }

        if ( 1 === count( $providers ) ) {
            return $providers[ 0 ];
        }

        return new ChainFraudProvider( $providers );
    }

    /**
     * Step 1 — creates a provider session (money held, not captured) and
     * pins its reference to the order so a repeat finalize can recover it.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order
     * @param  Cart                  $cart
     * @param  PaymentGateway        $gateway
     * @param  array<string, mixed>  $context
     *
     * @return PaymentSession
     */
    protected function authorize(
        Order $order,
        Cart $cart,
        PaymentGateway $gateway,
        array $context,
    ): PaymentSession {
        $session = $gateway->createPaymentSession( $cart, $context );

        $this->recordAuthorization( $order, $session );

        return $session;
    }

    /**
     * Step 2 — runs the active fraud provider against the authorized session.
     *
     * Wraps the call in the `ap.ecommerce.fraud.assessing` (pre) and
     * `ap.ecommerce.fraud.assessed` (post) hooks so subscribers can shape
     * the assessment context and override the verdict (e.g. chain-mode
     * composition). Engine spec §6.8.
     *
     * @since 1.0.0
     *
     * @param  Cart            $cart
     * @param  Address         $shipping
     * @param  PaymentSession  $session
     * @param  FraudProvider   $provider
     *
     * @return FraudDecision
     */
    protected function assess(
        Cart $cart,
        Address $shipping,
        PaymentSession $session,
        FraudProvider $provider,
    ): FraudDecision {
        applyFilters(
            'ap.ecommerce.fraud.assessing',
            [
                'shipping'      => $shipping->toArray(),
                'provider_key'  => $provider->key(),
                'session_ref'   => $session->reference,
            ],
            $cart,
        );

        $decision = $provider->assess( $cart, $shipping, $session );

        /** @var FraudDecision $filtered */
        $filtered = applyFilters( 'ap.ecommerce.fraud.assessed', $decision, $cart, $session );

        return $filtered instanceof FraudDecision ? $filtered : $decision;
    }

    /**
     * Handles a `block` verdict: void auth, mark order failed, persist
     * decision to `orders.meta.fraud_decision`, dispatch events.
     *
     * @since 1.0.0
     *
     * @param  Order           $order
     * @param  PaymentGateway  $gateway
     * @param  PaymentSession  $session
     * @param  FraudDecision   $decision
     *
     * @return PaymentFinalization
     */
    protected function handleBlock(
        Order $order,
        PaymentGateway $gateway,
        PaymentSession $session,
        FraudDecision $decision,
    ): PaymentFinalization {
        // Void first so a crash between void and DB commit still leaves
        // the money released at the provider — worst case is a stray
        // `failed` order pointing at a voided auth, which the ops team
        // can reconcile from the timeline entry we're about to write.
        $gateway->voidPendingPayment( $order );

        $refreshed = DB::transaction( function () use ( $order, $gateway, $decision ): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );

            $meta                     = (array) ( $locked->meta ?? [] );
            $meta[ 'fraud_decision' ] = $decision->toArray();

            $locked->meta           = $meta;
            $locked->system_status  = 'failed';
            $locked->payment_status = 'failed';
            $locked->save();

            OrderTimelineEntry::query()->create( [
                'order_id'      => $locked->id,
                'actor_user_id' => null,
                'event_type'    => 'payment.fraud_blocked',
                'payload'       => [
                    'gateway'  => $gateway->key(),
                    'verdict'  => $decision->verdict,
                    'score'    => $decision->score,
                    'reasons'  => $decision->reasons,
                ],
            ] );

            return $locked->fresh() ?? $locked;
        } );

        doAction( 'ap.ecommerce.fraud.blocked', $refreshed, $decision );

        Event::dispatch( new FraudBlocked( $refreshed, $decision ) );

        // Deliberately generic — fraud reasons live on
        // `orders.meta.fraud_decision` for auditors and on the
        // {@see FraudBlocked} event's decision object for listeners that
        // need them. Baking them into the exception message would leak
        // them through any subscriber that logs `$e->getMessage()`,
        // and engine plan §8.4 requires those reasons never surface
        // to customers.
        Event::dispatch( new PaymentFailed(
            $refreshed,
            $gateway,
            new RuntimeException( sprintf(
                'Fraud provider blocked order %d.',
                $refreshed->id,
            ) ),
        ) );

        return new PaymentFinalization(
            status: PaymentFinalization::STATUS_BLOCKED,
            order: $refreshed,
            session: $session,
            fraud: $decision,
        );
    }

    /**
     * Handles a `challenge` verdict: leave the auth open, dispatch
     * {@see FraudChallenged}, and return the step-up token so the client
     * can complete the challenge (typically 3DS).
     *
     * @since 1.0.0
     *
     * @param  Order           $order
     * @param  Cart            $cart
     * @param  PaymentSession  $session
     * @param  FraudDecision   $decision
     *
     * @return PaymentFinalization
     */
    protected function handleChallenge(
        Order $order,
        Cart $cart,
        PaymentSession $session,
        FraudDecision $decision,
    ): PaymentFinalization {
        OrderTimelineEntry::query()->create( [
            'order_id'      => $order->id,
            'actor_user_id' => null,
            'event_type'    => 'payment.fraud_challenged',
            'payload'       => [
                'verdict' => $decision->verdict,
                'score'   => $decision->score,
                'reasons' => $decision->reasons,
            ],
        ] );

        Event::dispatch( new FraudChallenged( $cart, $decision, $session ) );

        return new PaymentFinalization(
            status: PaymentFinalization::STATUS_CHALLENGED,
            order: $order,
            session: $session,
            fraud: $decision,
            stepUpToken: $session->clientSecret ?? $session->redirectUrl,
        );
    }

    /**
     * Step 3 — capture on approve. Success dispatches
     * {@see PaymentSucceeded}; a gateway that returns `success = false`
     * or throws dispatches {@see PaymentFailed}.
     *
     * @since 1.0.0
     *
     * @param  Order           $order
     * @param  PaymentGateway  $gateway
     * @param  PaymentSession  $session
     * @param  FraudDecision   $decision
     *
     * @return PaymentFinalization
     */
    protected function capture(
        Order $order,
        PaymentGateway $gateway,
        PaymentSession $session,
        FraudDecision $decision,
    ): PaymentFinalization {
        doAction( 'ap.ecommerce.payment.charging', $gateway, $session->amount, $order );

        try {
            $result = $gateway->capturePayment( $order, $session );
        } catch ( Throwable $e ) {
            $this->recordCaptureFailure( $order, $gateway, $session, $e->getMessage(), null );
            Event::dispatch( new PaymentFailed( $order->fresh() ?? $order, $gateway, $e ) );

            return new PaymentFinalization(
                status: PaymentFinalization::STATUS_FAILED,
                order: $order->fresh() ?? $order,
                session: $session,
                fraud: $decision,
            );
        }

        if ( ! $result->success ) {
            $this->recordCaptureFailure(
                $order,
                $gateway,
                $session,
                $result->errorMessage ?? ( $result->errorCode ?? 'unknown error' ),
                $result,
            );

            $refreshed = $order->fresh() ?? $order;

            $reason = new RuntimeException( sprintf(
                'Gateway "%s" declined capture for order %d: %s',
                $gateway->key(),
                $refreshed->id,
                $result->errorMessage ?? ( $result->errorCode ?? 'unknown error' ),
            ) );

            doAction( 'ap.ecommerce.payment.failed', $gateway, $reason, $refreshed );
            Event::dispatch( new PaymentFailed( $refreshed, $gateway, $reason ) );

            return new PaymentFinalization(
                status: PaymentFinalization::STATUS_FAILED,
                order: $refreshed,
                session: $session,
                fraud: $decision,
                payment: $result,
            );
        }

        $refreshed = $this->recordCaptureSuccess( $order, $gateway, $result );

        doAction( 'ap.ecommerce.payment.succeeded', $result, $refreshed );
        Event::dispatch( new PaymentSucceeded( $refreshed, $result ) );

        return new PaymentFinalization(
            status: PaymentFinalization::STATUS_CAPTURED,
            order: $refreshed,
            session: $session,
            fraud: $decision,
            payment: $result,
        );
    }

    /**
     * Records that authorization has been created for `$order` — pins the
     * session reference on `orders.payment_reference` and writes a timeline
     * entry so the sequence is auditable. Repeat calls for the same session
     * are a no-op.
     *
     * @since 1.0.0
     *
     * @param  Order           $order
     * @param  PaymentSession  $session
     *
     * @return void
     */
    protected function recordAuthorization( Order $order, PaymentSession $session ): void
    {
        DB::transaction( function () use ( $order, $session ): void {
            $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );

            if ( $locked->payment_reference === $session->reference ) {
                return;
            }

            $locked->payment_reference = $session->reference;
            $locked->save();

            OrderTimelineEntry::query()->create( [
                'order_id'      => $locked->id,
                'actor_user_id' => null,
                'event_type'    => 'payment.authorized',
                'payload'       => [
                    'gateway'   => $session->gatewayKey,
                    'reference' => $session->reference,
                    'amount'    => (string) $session->amount->getAmount(),
                    'currency'  => $session->amount->getCurrency()->getCode(),
                ],
            ] );
        } );
    }

    /**
     * Persists a successful capture: order goes to `processing` /
     * `payment_status = paid` and a timeline entry is written.
     *
     * @since 1.0.0
     *
     * @param  Order           $order
     * @param  PaymentGateway  $gateway
     * @param  PaymentResult   $result
     *
     * @return Order
     */
    protected function recordCaptureSuccess(
        Order $order,
        PaymentGateway $gateway,
        PaymentResult $result,
    ): Order {
        return DB::transaction( function () use ( $order, $gateway, $result ): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );

            $locked->payment_status = 'paid';
            if ( 'pending' === $locked->system_status ) {
                $locked->system_status = 'processing';
            }
            if ( null !== $result->gatewayReference ) {
                $locked->payment_reference = $result->gatewayReference;
            }
            $locked->save();

            OrderTimelineEntry::query()->create( [
                'order_id'      => $locked->id,
                'actor_user_id' => null,
                'event_type'    => 'payment.captured',
                'payload'       => [
                    'gateway'           => $gateway->key(),
                    'amount'            => (string) $result->amount->getAmount(),
                    'currency'          => $result->amount->getCurrency()->getCode(),
                    'gateway_reference' => $result->gatewayReference,
                ],
            ] );

            return $locked->fresh() ?? $locked;
        } );
    }

    /**
     * Persists a failed capture: writes a timeline entry (order status is
     * intentionally left untouched — retry is allowed and downstream policy
     * decides whether to transition to `failed` after N failed attempts).
     *
     * @since 1.0.0
     *
     * @param  Order              $order
     * @param  PaymentGateway     $gateway
     * @param  PaymentSession     $session
     * @param  string             $message
     * @param  PaymentResult|null $result
     *
     * @return void
     */
    protected function recordCaptureFailure(
        Order $order,
        PaymentGateway $gateway,
        PaymentSession $session,
        string $message,
        ?PaymentResult $result,
    ): void {
        OrderTimelineEntry::query()->create( [
            'order_id'      => $order->id,
            'actor_user_id' => null,
            'event_type'    => 'payment.capture_failed',
            'payload'       => [
                'gateway'   => $gateway->key(),
                'reference' => $session->reference,
                'message'   => $message,
                'code'      => $result?->errorCode,
                'retryable' => $result?->retryable,
            ],
        ] );
    }

    /**
     * Returns the recorded terminal outcome for a repeat finalize call on
     * an order that already reached one, or `null` when the order is still
     * in-flight.
     *
     * The ledger of record here is `orders.payment_status`:
     *
     * - `paid`   → `STATUS_CAPTURED` (session details reconstructed from `payment_reference`)
     * - `failed` when `meta.fraud_decision.verdict === 'block'` → `STATUS_BLOCKED`
     *
     * Any other state returns `null` so `finalize()` re-runs authorize +
     * assess + capture from scratch.
     *
     * @since 1.0.0
     *
     * @param  Order           $order
     * @param  PaymentGateway  $gateway
     *
     * @return PaymentFinalization|null
     */
    protected function recoverTerminalOutcome( Order $order, PaymentGateway $gateway ): ?PaymentFinalization
    {
        $paymentStatus = (string) $order->payment_status;

        if ( 'paid' === $paymentStatus ) {
            $reference = (string) ( $order->payment_reference ?? '' );

            $amount = new Money(
                (string) $order->total_amount,
                CurrencyVO::of( (string) $order->currency )->toMoneyPhp(),
            );

            $session = new PaymentSession(
                gatewayKey: $gateway->key(),
                reference: $reference,
                amount: $amount,
            );

            $result = PaymentResult::success( $session->amount, $reference );

            return new PaymentFinalization(
                status: PaymentFinalization::STATUS_CAPTURED,
                order: $order,
                session: $session,
                payment: $result,
            );
        }

        if ( 'failed' === $paymentStatus ) {
            $meta     = (array) ( $order->meta ?? [] );
            $fraudRaw = $meta[ 'fraud_decision' ] ?? null;

            if ( is_array( $fraudRaw ) && ( $fraudRaw[ 'verdict' ] ?? null ) === FraudDecision::VERDICT_BLOCK ) {
                $decision = new FraudDecision(
                    verdict: FraudDecision::VERDICT_BLOCK,
                    score: (int) ( $fraudRaw[ 'score' ] ?? 0 ),
                    reasons: array_values( array_map( 'strval', (array) ( $fraudRaw[ 'reasons' ] ?? [] ) ) ),
                    providerReference: isset( $fraudRaw[ 'provider_reference' ] )
                        ? (string) $fraudRaw[ 'provider_reference' ]
                        : null,
                );

                $reference = (string) ( $order->payment_reference ?? '' );

                $amount = new Money(
                    (string) $order->total_amount,
                    CurrencyVO::of( (string) $order->currency )->toMoneyPhp(),
                );

                $session = new PaymentSession(
                    gatewayKey: $gateway->key(),
                    reference: $reference,
                    amount: $amount,
                );

                return new PaymentFinalization(
                    status: PaymentFinalization::STATUS_BLOCKED,
                    order: $order,
                    session: $session,
                    fraud: $decision,
                );
            }
        }

        return null;
    }
}
