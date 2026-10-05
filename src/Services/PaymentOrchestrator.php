<?php

/**
 * PaymentOrchestrator.
 *
 * Owns the checkout finalize sequence — session → assess → capture — so
 * every {@see PaymentGateway} follows the same security-critical
 * choreography. Baking the sequence into individual gateways would
 * duplicate the fraud gate and let one gateway drift; centralising it here
 * means a new provider only implements the {@see PaymentGateway} contract.
 * Engine plan §8.4, spec §7 events #13/#14/#16/#17.
 *
 * A finalize first **claims** the order: under its row lock it checks the
 * order can still be paid (`system_status = pending`, `payment_status`
 * pending, or `processing` left behind by a crashed attempt whose lease ran
 * out), then marks it `processing` with an attempt id. A concurrent finalize
 * gets {@see PaymentInProgressException}; a repeat finalize after the order
 * reached a terminal state gets the recorded outcome without touching the
 * gateway.
 *
 * When the order already carries a `payment_reference` (the session the
 * shopper confirmed client-side), that session is **resumed** through
 * {@see PaymentGateway::retrievePaymentSession()} — never re-created, so a
 * retry can't authorize or charge twice. Its amount and currency must match
 * the order. The outcomes are:
 *
 * - **Captured** — the fraud provider approved, the gateway captured (or the
 *   session was already captured), the amount captured matches the order,
 *   and under the order lock the order moved to `processing` through
 *   {@see OrderStatusMachine}, `payment_status` → `paid`, and its stock
 *   reservations were committed. If the order was cancelled while the
 *   capture ran, the capture is refunded instead.
 * - **Challenged** — the session needs a step-up (3DS, a redirect): the
 *   result carries the step-up token and the order stays `pending`. Once the
 *   shopper completes it, {@see self::resume()} (or another finalize)
 *   re-assesses; a fraud `challenge` on a session that went through that
 *   step-up counts as satisfied. A `challenge` on a session that was already
 *   confirmed has no client-side remedy, so it holds the payment for review
 *   (no step-up token); only an in-process `accept_challenge` captures it.
 * - **Blocked** — the authorization is voided (a payment that was already
 *   captured is refunded), the order moves to `failed`, its reservations are
 *   released, and the reasons go to `orders.meta.fraud_decision` (never
 *   surfaced to the customer).
 * - **Failed** — the capture was declined or errored, or the session can't be
 *   paid; the order stays `pending` so the payment can be retried.
 *
 * Every lifecycle action fires once the change it reports has committed.
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
use ArtisanPackUI\Ecommerce\Exceptions\PaymentAmountMismatchException;
use ArtisanPackUI\Ecommerce\Exceptions\PaymentInProgressException;
use ArtisanPackUI\Ecommerce\Exceptions\PaymentNotAllowedException;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
     * `payment_status` while a finalize holds the order.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_PROCESSING = 'processing';

    /**
     * Seconds a finalize attempt keeps its claim. A `processing` order whose
     * attempt is older than this was left behind by a crash and may be
     * resumed.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const CLAIM_LEASE_SECONDS = 120;

    /**
     * `$context` key an admin passes (in-process only) to capture a payment
     * held for review after a fraud challenge.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ACCEPT_CHALLENGE = 'accept_challenge';

    /**
     * Payment statuses that mean the money was captured at some point.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected const CAPTURED_PAYMENT_STATUSES = [ 'paid', 'partially_refunded', 'refunded' ];

    /**
     * @since 1.0.0
     *
     * @param  PaymentGatewayRegistry  $gateways        Gateways.
     * @param  FraudProviderRegistry   $fraudProviders  Fraud providers.
     * @param  Config                  $config          Config.
     * @param  OrderStatusMachine      $statuses        Order status transitions.
     * @param  InventoryService        $inventory       Stock reservations.
     */
    public function __construct(
        protected PaymentGatewayRegistry $gateways,
        protected FraudProviderRegistry $fraudProviders,
        protected Config $config,
        protected OrderStatusMachine $statuses,
        protected InventoryService $inventory,
    ) {
    }

    /**
     * Runs the session → assess → capture sequence against `$order`.
     *
     * `$order` must carry a `payment_gateway_key` pointing at a registered
     * gateway. When it also carries a `payment_reference`, that session is
     * resumed; otherwise a new session is created from `$cart` (a
     * server-driven flow). `$context` is forwarded to the gateway — pass the
     * request idempotency key through here so provider-side retries are safe
     * (engine spec §11.2).
     *
     * @since 1.0.0
     *
     * @param  Order                 $order     Order to finalize.
     * @param  Cart                  $cart      Cart the order was placed from.
     * @param  Address               $shipping  Destination the fraud provider assesses against.
     * @param  array<string, mixed>  $context   Provider hints; `accept_challenge` (admin only) captures a payment held for review.
     *
     * @throws RuntimeException            When the gateway or fraud provider is not registered.
     * @throws PaymentNotAllowedException  When the order can no longer be paid.
     * @throws PaymentInProgressException  When another finalize of the order is running.
     *
     * @return PaymentFinalization
     */
    public function finalize( Order $order, Cart $cart, Address $shipping, array $context = [] ): PaymentFinalization
    {
        return $this->run( $order, $cart, $shipping, $context, false );
    }

    /**
     * Resumes the session already pinned to `$order` — after the shopper
     * completed a 3DS step-up, or to retry a capture — and settles it like
     * {@see self::finalize()}. It never creates a session, so it can't
     * authorize or charge twice.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order    Order with a `payment_reference`.
     * @param  array<string, mixed>  $context  As for finalize().
     *
     * @throws PaymentNotAllowedException  When the order has no session or can no longer be paid.
     * @throws PaymentInProgressException  When another finalize of the order is running.
     *
     * @return PaymentFinalization
     */
    public function resume( Order $order, array $context = [] ): PaymentFinalization
    {
        return $this->run( $order, $this->cartFor( $order ), $this->addressFor( $order ), $context, true );
    }

    /**
     * The shared sequence behind finalize() and resume().
     *
     * @since 1.0.0
     *
     * @param  Order                 $order     Order.
     * @param  Cart                  $cart      Source cart.
     * @param  Address               $shipping  Destination.
     * @param  array<string, mixed>  $context   Provider hints.
     * @param  bool                  $resuming  Whether an existing session is required.
     *
     * @return PaymentFinalization
     */
    protected function run( Order $order, Cart $cart, Address $shipping, array $context, bool $resuming ): PaymentFinalization
    {
        $gateway       = $this->resolveGateway( $order );
        $fraudProvider = $this->resolveFraudProvider();

        [ $claimed, $recorded, $attemptId ] = $this->claim( $order, $gateway, $resuming );

        if ( null !== $recorded ) {
            return $recorded;
        }

        try {
            $existingReference = (string) ( $claimed->payment_reference ?? '' );
            $session           = '' !== $existingReference
                ? $gateway->retrievePaymentSession( $existingReference )
                : $this->authorize( $claimed, $cart, $gateway, $context );

            $this->assertSessionMatchesOrder( $claimed, $gateway, $session, $attemptId );

            if ( $session->isCanceled() ) {
                return $this->failSession( $claimed, $gateway, $session, $attemptId, 'payment_session_canceled', __( 'The payment session was cancelled at the provider.' ) );
            }

            if ( $session->requiresAction() ) {
                return $this->requireStepUp( $claimed, $cart, $session, $attemptId );
            }

            // A resumed session the shopper never confirmed can't be captured.
            if ( '' !== $existingReference && PaymentSession::STATUS_REQUIRES_PAYMENT_METHOD === $session->status ) {
                return $this->failSession( $claimed, $gateway, $session, $attemptId, 'payment_not_confirmed', __( 'The payment has not been confirmed yet.' ) );
            }

            $decision = $this->settleChallenge( $claimed, $session, $this->assess( $cart, $shipping, $session, $fraudProvider ), $context );

            if ( $decision->isBlock() ) {
                return $this->handleBlock( $claimed, $gateway, $session, $decision );
            }

            if ( $decision->isChallenge() ) {
                return $this->handleChallenge( $claimed, $cart, $session, $decision, $attemptId );
            }

            return $this->capture( $claimed, $gateway, $session, $decision, $attemptId );
        } catch ( Throwable $exception ) {
            $this->releaseClaim( $claimed, $attemptId );

            throw $exception;
        }
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
     * Claims `$order` for this finalize under its row lock: returns the
     * recorded outcome when it already reached a terminal state, refuses an
     * order that can no longer be paid or is being finalized right now, and
     * otherwise marks it `processing` with a fresh attempt id.
     *
     * @since 1.0.0
     *
     * @param  Order           $order     Order.
     * @param  PaymentGateway  $gateway   Its gateway.
     * @param  bool            $resuming  Whether a pinned session is required.
     *
     * @throws PaymentNotAllowedException  When the order can no longer be paid.
     * @throws PaymentInProgressException  When another attempt holds a live claim.
     *
     * @return array{0: Order, 1: PaymentFinalization|null, 2: string|null}
     */
    protected function claim( Order $order, PaymentGateway $gateway, bool $resuming ): array
    {
        return DB::transaction( function () use ( $order, $gateway, $resuming ): array {
            $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );

            $recorded = $this->recoverTerminalOutcome( $locked, $gateway );

            if ( null !== $recorded ) {
                return [ $locked, $recorded, null ];
            }

            if ( 'pending' !== (string) $locked->system_status ) {
                throw new PaymentNotAllowedException( __( 'Order :order is :status, so its payment can no longer be finalized.', [
                    'order'  => $locked->order_number,
                    'status' => (string) $locked->system_status,
                ] ), [ 'order_id' => $locked->id ] );
            }

            $meta    = (array) ( $locked->meta ?? [] );
            $attempt = (array) ( $meta['finalize_attempt'] ?? [] );

            if ( self::STATUS_PROCESSING === (string) $locked->payment_status ) {
                $startedAt = isset( $attempt['at'] ) ? Carbon::parse( (string) $attempt['at'] ) : null;

                if ( null !== $startedAt && $startedAt->greaterThan( Carbon::now()->subSeconds( self::CLAIM_LEASE_SECONDS ) ) ) {
                    throw new PaymentInProgressException( __( 'The payment for order :order is already being processed.', [ 'order' => $locked->order_number ] ), [ 'order_id' => $locked->id ] );
                }
            } elseif ( 'pending' !== (string) $locked->payment_status ) {
                throw new PaymentNotAllowedException( __( 'The payment for order :order is :status and can no longer be finalized.', [
                    'order'  => $locked->order_number,
                    'status' => (string) $locked->payment_status,
                ] ), [ 'order_id' => $locked->id ] );
            }

            if ( $resuming && '' === (string) ( $locked->payment_reference ?? '' ) ) {
                throw new PaymentNotAllowedException( __( 'Order :order has no payment session to resume.', [ 'order' => $locked->order_number ] ), [ 'order_id' => $locked->id ] );
            }

            $attemptId                = (string) Str::uuid();
            $meta['finalize_attempt'] = [ 'id' => $attemptId, 'at' => Carbon::now()->toIso8601String() ];

            $locked->meta           = $meta;
            $locked->payment_status = self::STATUS_PROCESSING;
            $locked->save();

            return [ $locked->fresh() ?? $locked, null, $attemptId ];
        } );
    }

    /**
     * Gives the order back after an attempt that didn't settle it: if this
     * attempt still holds the claim, `payment_status` returns to
     * `$status` (`pending` by default) and the claim is dropped.
     *
     * @since 1.0.0
     *
     * @param  Order        $order      Order.
     * @param  string|null  $attemptId  The attempt's id.
     * @param  string       $status     Payment status to restore.
     * @param  array<string, mixed>  $meta  Meta keys to merge in at the same time.
     *
     * @return Order
     */
    protected function releaseClaim( Order $order, ?string $attemptId, string $status = 'pending', array $meta = [] ): Order
    {
        if ( null === $attemptId ) {
            return $order;
        }

        return DB::transaction( function () use ( $order, $attemptId, $status, $meta ): Order {
            $locked  = Order::query()->lockForUpdate()->findOrFail( $order->id );
            $current = (array) ( $locked->meta ?? [] );

            if ( ( $current['finalize_attempt']['id'] ?? null ) !== $attemptId ) {
                return $locked;
            }

            unset( $current['finalize_attempt'] );

            $locked->meta           = array_replace( $current, $meta );
            $locked->payment_status = self::STATUS_PROCESSING === (string) $locked->payment_status ? $status : $locked->payment_status;
            $locked->save();

            return $locked->fresh() ?? $locked;
        } );
    }

    /**
     * Creates a provider session for a server-driven flow (no session was
     * pinned at checkout) and pins its reference to the order.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order    Order.
     * @param  Cart                  $cart     Source cart.
     * @param  PaymentGateway        $gateway  Gateway.
     * @param  array<string, mixed>  $context  Provider hints.
     *
     * @return PaymentSession
     */
    protected function authorize( Order $order, Cart $cart, PaymentGateway $gateway, array $context ): PaymentSession
    {
        $session = $gateway->createPaymentSession( $cart, $this->gatewayContext( $context ) );

        $this->recordAuthorization( $order, $session );

        return $session;
    }

    /**
     * Refuses a session whose amount or currency differs from the order's
     * total (the cart changed after the session was created, or a gateway
     * reported something else). An uncaptured session is voided; a captured
     * one is refunded. The order keeps no reference to it, so a retry pins a
     * fresh session.
     *
     * @since 1.0.0
     *
     * @param  Order           $order      Claimed order.
     * @param  PaymentGateway  $gateway    Gateway.
     * @param  PaymentSession  $session    Session.
     * @param  string|null     $attemptId  Claim.
     *
     * @throws PaymentAmountMismatchException When they differ.
     *
     * @return void
     */
    protected function assertSessionMatchesOrder( Order $order, PaymentGateway $gateway, PaymentSession $session, ?string $attemptId ): void
    {
        $expected = $this->orderTotal( $order );

        if ( $session->amount->equals( $expected ) ) {
            return;
        }

        $this->releaseMoney( $order, $gateway, $session, 'amount_mismatch' );

        $this->releaseClaim( $order, $attemptId, 'pending', [
            'payment_review' => [
                'reason'   => 'session_amount_mismatch',
                'expected' => (int) $expected->getAmount(),
                'actual'   => (int) $session->amount->getAmount(),
                'currency' => $session->amount->getCurrency()->getCode(),
            ],
        ] );

        DB::transaction( function () use ( $order, $session, $expected ): void {
            $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );

            if ( $locked->payment_reference === $session->reference ) {
                $locked->payment_reference = null;
                $locked->save();
            }

            OrderTimelineEntry::query()->create( [
                'order_id'      => $locked->id,
                'actor_user_id' => null,
                'event_type'    => 'payment.amount_mismatch',
                'payload'       => [
                    'reference' => $session->reference,
                    'expected'  => (string) $expected->getAmount(),
                    'actual'    => (string) $session->amount->getAmount(),
                    'currency'  => $session->amount->getCurrency()->getCode(),
                ],
            ] );
        } );

        throw new PaymentAmountMismatchException( __( 'The payment for order :order does not match its total.', [ 'order' => $order->order_number ] ), [
            'order_id'  => $order->id,
            'reference' => $session->reference,
        ] );
    }

    /**
     * Step 2 — runs the active fraud provider against the session.
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
     * Turns a `challenge` into an approval when it has been answered: the
     * shopper completed a step-up for this very session, or an admin passed
     * `accept_challenge`. Anything else is returned unchanged.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order     Claimed order.
     * @param  PaymentSession        $session   Session.
     * @param  FraudDecision         $decision  Provider verdict.
     * @param  array<string, mixed>  $context   Caller context.
     *
     * @return FraudDecision
     */
    protected function settleChallenge( Order $order, PaymentSession $session, FraudDecision $decision, array $context ): FraudDecision
    {
        if ( ! $decision->isChallenge() ) {
            return $decision;
        }

        $meta      = (array) ( $order->meta ?? [] );
        $steppedUp = ( $meta['payment']['step_up_reference'] ?? null ) === $session->reference && $session->isConfirmed();

        if ( $steppedUp ) {
            return FraudDecision::approve( $decision->score, [ ...$decision->reasons, 'step_up_completed' ], $decision->providerReference );
        }

        if ( true === ( $context[ self::ACCEPT_CHALLENGE ] ?? false ) ) {
            return FraudDecision::approve( $decision->score, [ ...$decision->reasons, 'review_accepted' ], $decision->providerReference );
        }

        return $decision;
    }

    /**
     * The session needs the shopper to complete a step-up (3DS, a
     * redirect): remember that, give the order back, and hand out the
     * step-up token.
     *
     * @since 1.0.0
     *
     * @param  Order           $order      Claimed order.
     * @param  Cart            $cart       Source cart.
     * @param  PaymentSession  $session    Session.
     * @param  string|null     $attemptId  Claim.
     *
     * @return PaymentFinalization
     */
    protected function requireStepUp( Order $order, Cart $cart, PaymentSession $session, ?string $attemptId ): PaymentFinalization
    {
        $meta                         = (array) ( $order->meta ?? [] );
        $payment                      = (array) ( $meta['payment'] ?? [] );
        $payment['step_up_reference'] = $session->reference;

        $released = $this->releaseClaim( $order, $attemptId, 'pending', [ 'payment' => $payment ] );

        OrderTimelineEntry::query()->create( [
            'order_id'      => $order->id,
            'actor_user_id' => null,
            'event_type'    => 'payment.action_required',
            'payload'       => [ 'gateway' => $session->gatewayKey, 'reference' => $session->reference ],
        ] );

        $decision = FraudDecision::challenge( 0, [ 'step_up_required' ] );

        Event::dispatch( new FraudChallenged( $cart, $decision, $session ) );

        return new PaymentFinalization(
            status: PaymentFinalization::STATUS_CHALLENGED,
            order: $released,
            session: $session,
            fraud: $decision,
            stepUpToken: $session->stepUpToken(),
        );
    }

    /**
     * The session can't be paid (cancelled at the provider, or never
     * confirmed): record the attempt, give the order back, report failure.
     *
     * @since 1.0.0
     *
     * @param  Order           $order      Claimed order.
     * @param  PaymentGateway  $gateway    Gateway.
     * @param  PaymentSession  $session    Session.
     * @param  string|null     $attemptId  Claim.
     * @param  string          $code       Failure code.
     * @param  string          $message    Failure message.
     *
     * @return PaymentFinalization
     */
    protected function failSession( Order $order, PaymentGateway $gateway, PaymentSession $session, ?string $attemptId, string $code, string $message ): PaymentFinalization
    {
        $result = PaymentResult::terminalFailure( $session->amount, $code, $message );

        $this->recordCaptureFailure( $order, $gateway, $session, $message, $result );

        $released = $this->releaseClaim( $order, $attemptId );
        $reason   = new RuntimeException( $message );

        // A cancelled session is dead: unpin it so a retry can pin a new one.
        if ( $session->isCanceled() && $released->payment_reference === $session->reference ) {
            Order::query()->whereKey( $released->id )->where( 'payment_reference', $session->reference )->update( [ 'payment_reference' => null ] );
            $released->payment_reference = null;
        }

        doAction( 'ap.ecommerce.payment.failed', $gateway, $reason, $released );
        Event::dispatch( new PaymentFailed( $released, $gateway, $reason ) );

        return new PaymentFinalization(
            status: PaymentFinalization::STATUS_FAILED,
            order: $released,
            session: $session,
            payment: $result,
        );
    }

    /**
     * Handles a `block` verdict: release the money (void, or refund when it
     * was already captured), move the order to `failed` through the status
     * machine, release its stock, persist the decision to
     * `orders.meta.fraud_decision`, and dispatch events.
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
        // Release the money first so a crash before the DB write still
        // leaves it released at the provider. Best-effort: a refused void
        // must not keep a blocked order open, and an unvoided authorization
        // expires at the provider on its own.
        $released = $this->releaseMoney( $order, $gateway, $session, 'fraudulent' );

        $refreshed = DB::transaction( function () use ( $order, $gateway, $decision, $released ): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );

            $meta                     = (array) ( $locked->meta ?? [] );
            $meta[ 'fraud_decision' ] = $decision->toArray();
            unset( $meta['finalize_attempt'] );

            $locked->meta           = $meta;
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
                    'released' => $released,
                ],
            ] );

            $this->inventory->releaseFor( $locked );
            $this->statuses->transition( $locked, 'failed', null, 'payment.fraud_blocked' );

            return $locked->fresh() ?? $locked;
        } );

        doAction( 'ap.ecommerce.fraud.blocked', $refreshed, $decision );

        Event::dispatch( new FraudBlocked( $refreshed, $decision ) );

        // Deliberately generic — fraud reasons live on
        // `orders.meta.fraud_decision` for auditors and on the
        // {@see FraudBlocked} event's decision object for listeners that
        // need them; engine plan §8.4 requires they never reach customers.
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
     * Handles a `challenge` verdict. The order stays `pending` with the
     * decision on `orders.meta.fraud_decision`.
     *
     * - On a session the shopper hasn't confirmed yet, the step-up token is
     *   returned so they can confirm (and authenticate) it; the next
     *   finalize re-assesses the confirmed payment from scratch.
     * - On a confirmed session there is nothing left for the shopper to do,
     *   so the payment is held for review: no step-up token, the money stays
     *   authorized (or captured), and an admin captures it with
     *   `resume( $order, [ 'accept_challenge' => true ] )` or cancels the
     *   order.
     *
     * @since 1.0.0
     *
     * @param  Order           $order      Claimed order.
     * @param  Cart            $cart       Source cart.
     * @param  PaymentSession  $session    Session.
     * @param  FraudDecision   $decision   Challenge verdict.
     * @param  string|null     $attemptId  Claim.
     *
     * @return PaymentFinalization
     */
    protected function handleChallenge(
        Order $order,
        Cart $cart,
        PaymentSession $session,
        FraudDecision $decision,
        ?string $attemptId,
    ): PaymentFinalization {
        $released = $this->releaseClaim( $order, $attemptId, 'pending', [ 'fraud_decision' => $decision->toArray() ] );

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
            order: $released,
            session: $session,
            fraud: $decision,
            stepUpToken: $session->isConfirmed() ? null : $session->stepUpToken(),
        );
    }

    /**
     * Step 3 — capture on approve. A session the provider reports as
     * already captured is recorded without calling capture again, so a
     * retry after a crash between capture and the database write can't
     * charge twice. Success fires `ap.ecommerce.payment.succeeded` then
     * `ap.ecommerce.order.paid` and dispatches {@see PaymentSucceeded}; a
     * gateway that returns `success = false` or throws fires
     * `ap.ecommerce.payment.failed` and dispatches {@see PaymentFailed}.
     *
     * @since 1.0.0
     *
     * @param  Order           $order
     * @param  PaymentGateway  $gateway
     * @param  PaymentSession  $session
     * @param  FraudDecision   $decision
     * @param  string|null     $attemptId
     *
     * @return PaymentFinalization
     */
    protected function capture(
        Order $order,
        PaymentGateway $gateway,
        PaymentSession $session,
        FraudDecision $decision,
        ?string $attemptId,
    ): PaymentFinalization {
        doAction( 'ap.ecommerce.payment.charging', $gateway, $session->amount, $order );

        if ( $session->isCaptured() ) {
            $result = PaymentResult::success( $session->amount, isset( $session->metadata['charge'] ) ? (string) $session->metadata['charge'] : $session->reference );
        } else {
            try {
                $result = $gateway->capturePayment( $order, $session );
            } catch ( Throwable $e ) {
                return $this->captureFailed( $order, $gateway, $session, $decision, $attemptId, $e, null );
            }
        }

        if ( ! $result->success ) {
            $reason = new RuntimeException( sprintf(
                'Gateway "%s" declined capture for order %d: %s',
                $gateway->key(),
                $order->id,
                $result->errorMessage ?? ( $result->errorCode ?? 'unknown error' ),
            ) );

            return $this->captureFailed( $order, $gateway, $session, $decision, $attemptId, $reason, $result );
        }

        // The provider moved a different amount than the order is for:
        // never mark that paid. The order is flagged for review instead.
        if ( ! $result->amount->equals( $this->orderTotal( $order ) ) ) {
            $released = $this->releaseClaim( $order, $attemptId, 'pending', [
                'payment_review' => [
                    'reason'    => 'captured_amount_mismatch',
                    'expected'  => (int) $order->total_amount,
                    'actual'    => (int) $result->amount->getAmount(),
                    'currency'  => $result->amount->getCurrency()->getCode(),
                    'reference' => $result->gatewayReference,
                ],
            ] );

            $reason = new PaymentAmountMismatchException( __( 'The payment for order :order does not match its total.', [ 'order' => $order->order_number ] ) );

            $this->recordCaptureFailure( $order, $gateway, $session, $reason->getMessage(), $result );
            doAction( 'ap.ecommerce.payment.failed', $gateway, $reason, $released );
            Event::dispatch( new PaymentFailed( $released, $gateway, $reason ) );

            return new PaymentFinalization(
                status: PaymentFinalization::STATUS_FAILED,
                order: $released,
                session: $session,
                fraud: $decision,
                payment: $result,
            );
        }

        [ $refreshed, $cancelled ] = $this->recordCaptureSuccess( $order, $gateway, $result, $session );

        if ( $cancelled ) {
            return $this->refundCaptureOnCancelledOrder( $refreshed, $gateway, $session, $decision, $result );
        }

        // The money has moved and the capture is committed: a throwing
        // listener must not turn this into a failed checkout.
        $this->afterCapture( 'ap.ecommerce.payment.succeeded', $refreshed, $result, $refreshed );
        $this->afterCapture( 'ap.ecommerce.order.paid', $refreshed, $refreshed, $result );
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
     * Records a capture that threw or was declined and gives the order back
     * for a retry.
     *
     * @since 1.0.0
     *
     * @param  Order               $order      Claimed order.
     * @param  PaymentGateway      $gateway    Gateway.
     * @param  PaymentSession      $session    Session.
     * @param  FraudDecision       $decision   Approved verdict.
     * @param  string|null         $attemptId  Claim.
     * @param  Throwable           $reason     Why it failed.
     * @param  PaymentResult|null  $result     Gateway result, when it returned one.
     *
     * @return PaymentFinalization
     */
    protected function captureFailed(
        Order $order,
        PaymentGateway $gateway,
        PaymentSession $session,
        FraudDecision $decision,
        ?string $attemptId,
        Throwable $reason,
        ?PaymentResult $result,
    ): PaymentFinalization {
        $this->recordCaptureFailure(
            $order,
            $gateway,
            $session,
            null === $result ? $reason->getMessage() : ( $result->errorMessage ?? ( $result->errorCode ?? 'unknown error' ) ),
            $result,
        );

        $released = $this->releaseClaim( $order, $attemptId );

        doAction( 'ap.ecommerce.payment.failed', $gateway, $reason, $released );
        Event::dispatch( new PaymentFailed( $released, $gateway, $reason ) );

        return new PaymentFinalization(
            status: PaymentFinalization::STATUS_FAILED,
            order: $released,
            session: $session,
            fraud: $decision,
            payment: $result,
        );
    }

    /**
     * Fires a post-capture action, logging instead of rethrowing when a
     * listener throws. Each action is isolated, so one failing listener
     * can't stop the next action, the {@see PaymentSucceeded} event, or
     * the captured outcome from being returned.
     *
     * @since 1.0.0
     *
     * @param  string  $hook     Action name.
     * @param  Order   $order    Captured order, for the log context.
     * @param  mixed   ...$args  Action arguments.
     *
     * @return void
     */
    protected function afterCapture( string $hook, Order $order, mixed ...$args ): void
    {
        try {
            doAction( $hook, ...$args );
        } catch ( Throwable $e ) {
            Log::channel( 'ecommerce' )->error( 'A post-capture listener failed; the capture stands.', [
                'hook'      => $hook,
                'order_id'  => $order->id,
                'exception' => $e->getMessage(),
            ] );
        }
    }

    /**
     * Pins a newly created session's reference to `$order` and writes a
     * timeline entry. An order that already has a reference keeps it.
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

            if ( null !== $locked->payment_reference && '' !== $locked->payment_reference ) {
                return;
            }

            $locked->payment_reference = $session->reference;
            $locked->save();

            $order->payment_reference = $session->reference;

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
     * Persists a successful capture under the order's lock: if the order
     * was cancelled while the capture ran, nothing is changed and the caller
     * refunds. Otherwise `payment_status` → `paid`, the order moves
     * `pending` → `processing` through {@see OrderStatusMachine} (so the
     * status hook, event, and timeline entry fire), and its stock
     * reservations are committed — all in one transaction. The session
     * reference stays on `payment_reference` (refunds and voids use it); the
     * capture's own id goes to `meta.payment.capture_reference`.
     *
     * @since 1.0.0
     *
     * @param  Order           $order    Claimed order.
     * @param  PaymentGateway  $gateway  Gateway.
     * @param  PaymentResult   $result   Successful capture.
     * @param  PaymentSession  $session  Captured session.
     *
     * @return array{0: Order, 1: bool} The order, and whether it had been cancelled.
     */
    protected function recordCaptureSuccess(
        Order $order,
        PaymentGateway $gateway,
        PaymentResult $result,
        PaymentSession $session,
    ): array {
        return DB::transaction( function () use ( $order, $gateway, $result, $session ): array {
            $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );

            if ( 'pending' !== (string) $locked->system_status ) {
                return [ $locked, true ];
            }

            $meta    = (array) ( $locked->meta ?? [] );
            $payment = (array) ( $meta['payment'] ?? [] );

            $payment['capture_reference'] = $result->gatewayReference;
            $payment['captured_at']       = Carbon::now()->toIso8601String();
            $meta['payment']              = $payment;
            unset( $meta['finalize_attempt'], $meta['payment_review'] );

            $locked->meta              = $meta;
            $locked->payment_status    = 'paid';
            $locked->payment_reference = ( $locked->payment_reference ?? '' ) !== '' ? $locked->payment_reference : $session->reference;
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

            $this->inventory->commitFor( $locked );
            $this->statuses->transition( $locked, 'processing', null, 'payment.captured' );

            return [ $locked->fresh() ?? $locked, false ];
        } );
    }

    /**
     * The order was cancelled while its payment was being captured: refund
     * the capture in full and record it.
     *
     * @since 1.0.0
     *
     * @param  Order           $order     Cancelled order.
     * @param  PaymentGateway  $gateway   Gateway.
     * @param  PaymentSession  $session   Captured session.
     * @param  FraudDecision   $decision  Verdict.
     * @param  PaymentResult   $result    The capture.
     *
     * @return PaymentFinalization
     */
    protected function refundCaptureOnCancelledOrder( Order $order, PaymentGateway $gateway, PaymentSession $session, FraudDecision $decision, PaymentResult $result ): PaymentFinalization
    {
        $refunded = false;

        try {
            $refund   = $gateway->refund( $order, $result->amount, 'requested_by_customer', [ 'idempotency_key' => 'ap-ec-cancel-refund-' . $order->id ] );
            $refunded = $refund->success;
        } catch ( Throwable $exception ) {
            Log::channel( 'ecommerce' )->critical( 'A payment captured on a cancelled order could not be refunded; refund it manually.', [
                'order_id'  => $order->id,
                'gateway'   => $gateway->key(),
                'reference' => $session->reference,
                'error'     => $exception->getMessage(),
            ] );
        }

        $refreshed = DB::transaction( function () use ( $order, $result, $refunded, $session ): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail( $order->id );
            $meta   = (array) ( $locked->meta ?? [] );
            unset( $meta['finalize_attempt'] );

            if ( $refunded ) {
                $locked->payment_status          = 'refunded';
                $locked->total_refunded_amount   = (int) $result->amount->getAmount();
                $locked->total_refunded_currency = $result->amount->getCurrency()->getCode();
            } else {
                $locked->payment_status = 'paid';
                $meta['payment_review'] = [ 'reason' => 'captured_after_cancel', 'reference' => $session->reference ];
            }

            $locked->meta = $meta;
            $locked->save();

            OrderTimelineEntry::query()->create( [
                'order_id'      => $locked->id,
                'actor_user_id' => null,
                'event_type'    => $refunded ? 'payment.refunded_after_cancel' : 'payment.captured_after_cancel',
                'payload'       => [
                    'amount'    => (string) $result->amount->getAmount(),
                    'currency'  => $result->amount->getCurrency()->getCode(),
                    'reference' => $result->gatewayReference,
                ],
            ] );

            return $locked->fresh() ?? $locked;
        } );

        return new PaymentFinalization(
            status: PaymentFinalization::STATUS_FAILED,
            order: $refreshed,
            session: $session,
            fraud: $decision,
            payment: $result,
        );
    }

    /**
     * Releases a session's money at the provider, best-effort: an
     * uncaptured session is voided, a captured one refunded in full.
     *
     * @since 1.0.0
     *
     * @param  Order           $order    Order the session is pinned to.
     * @param  PaymentGateway  $gateway  Gateway.
     * @param  PaymentSession  $session  Session.
     * @param  string          $reason   Why (refund reason / log).
     *
     * @return string|null `voided`, `refunded`, or null when nothing could be released.
     */
    protected function releaseMoney( Order $order, PaymentGateway $gateway, PaymentSession $session, string $reason ): ?string
    {
        try {
            if ( $session->isCaptured() ) {
                $refund = $gateway->refund( $order, $session->amount, 'fraudulent' === $reason ? 'fraudulent' : null, [ 'idempotency_key' => 'ap-ec-release-' . $session->reference ] );

                return $refund->success ? 'refunded' : null;
            }

            $pinned = clone $order;
            $pinned->setAttribute( 'payment_reference', $session->reference );
            $gateway->voidPendingPayment( $pinned );

            return 'voided';
        } catch ( Throwable $exception ) {
            Log::channel( 'ecommerce' )->warning( 'Could not release a payment session; it will expire at the provider or needs a manual refund.', [
                'order_id'  => $order->getKey(),
                'gateway'   => $gateway->key(),
                'reference' => $session->reference,
                'reason'    => $reason,
                'error'     => $exception->getMessage(),
            ] );

            return null;
        }
    }

    /**
     * Persists a failed capture attempt as a timeline entry.
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
     * Returns the recorded terminal outcome for a repeat finalize on an
     * order that already reached one, or `null` when it is still in flight:
     *
     * - captured at some point (`paid`, `partially_refunded`, `refunded`) → `STATUS_CAPTURED`
     * - `failed` with `meta.fraud_decision.verdict === 'block'` → `STATUS_BLOCKED`
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
        $session       = new PaymentSession(
            gatewayKey: $gateway->key(),
            reference: (string) ( $order->payment_reference ?? '' ),
            amount: $this->orderTotal( $order ),
        );

        if ( in_array( $paymentStatus, self::CAPTURED_PAYMENT_STATUSES, true ) ) {
            $reference = (string) ( $order->meta['payment']['capture_reference'] ?? $order->payment_reference ?? '' );

            return new PaymentFinalization(
                status: PaymentFinalization::STATUS_CAPTURED,
                order: $order,
                session: $session,
                payment: PaymentResult::success( $session->amount, $reference ),
            );
        }

        if ( 'failed' === $paymentStatus ) {
            $fraudRaw = ( (array) ( $order->meta ?? [] ) )[ 'fraud_decision' ] ?? null;

            if ( is_array( $fraudRaw ) && ( $fraudRaw[ 'verdict' ] ?? null ) === FraudDecision::VERDICT_BLOCK ) {
                return new PaymentFinalization(
                    status: PaymentFinalization::STATUS_BLOCKED,
                    order: $order,
                    session: $session,
                    fraud: new FraudDecision(
                        verdict: FraudDecision::VERDICT_BLOCK,
                        score: (int) ( $fraudRaw[ 'score' ] ?? 0 ),
                        reasons: array_values( array_map( 'strval', (array) ( $fraudRaw[ 'reasons' ] ?? [] ) ) ),
                        providerReference: isset( $fraudRaw[ 'provider_reference' ] ) ? (string) $fraudRaw[ 'provider_reference' ] : null,
                    ),
                );
            }
        }

        return null;
    }

    /**
     * The order's total as Money in its currency.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return Money
     */
    protected function orderTotal( Order $order ): Money
    {
        return new Money( (string) (int) $order->total_amount, CurrencyVO::of( (string) $order->currency )->toMoneyPhp() );
    }

    /**
     * The context passed to gateways, without engine-only keys.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $context  Caller context.
     *
     * @return array<string, mixed>
     */
    protected function gatewayContext( array $context ): array
    {
        unset( $context[ self::ACCEPT_CHALLENGE ] );

        return $context;
    }

    /**
     * The cart an order was placed from (its fraud-assessment subject), or
     * an unsaved stand-in built from the order when there is none.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return Cart
     */
    protected function cartFor( Order $order ): Cart
    {
        return Cart::query()->with( 'items' )->where( 'completed_order_id', $order->id )->first()
            ?? new Cart( [
                'customer_id'  => $order->customer_id,
                'email'        => $order->email,
                'currency'     => $order->currency,
                'total_amount' => $order->total_amount,
            ] );
    }

    /**
     * The address a resumed payment is assessed against: the order's
     * shipping address, else its billing address, else the store country.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return Address
     */
    protected function addressFor( Order $order ): Address
    {
        $address = (array) ( $order->shipping_address ?? $order->billing_address ?? [] );

        if ( 2 !== strlen( (string) ( $address['country_code'] ?? '' ) ) ) {
            $address['country_code'] = (string) $this->config->get( 'artisanpack.ecommerce.store.country', 'US' );
        }

        return Address::fromArray( $address );
    }
}
