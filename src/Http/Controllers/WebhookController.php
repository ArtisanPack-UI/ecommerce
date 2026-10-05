<?php

/**
 * WebhookController.
 *
 * Single inbound entry point for every payment provider registered in
 * {@see PaymentGatewayRegistry}. `POST /ecommerce/webhooks/{provider}`
 * resolves the gateway from `{provider}`, delegates signature verification
 * to its {@see \ArtisanPackUI\Ecommerce\Contracts\PaymentGateway::handleWebhook()}
 * implementation, dedupes replays via {@see IdempotencyRecord}, and
 * records the delivery in {@see InboundWebhookDelivery} so operators can
 * inspect and replay what came in. Unknown providers (404) and bodies over
 * `webhooks.inbound_max_bytes` (413) are refused before anything is
 * stored; unverified requests keep only a hash, the size, and the first
 * kilobyte. Every request counts against the caller's IP; only verified
 * ones count against the provider's allowance.
 *
 * A verified event fans out to three hook names — the generic
 * `ap.ecommerce.webhook_received`, the provider-scoped
 * `ap.ecommerce.gateway.{provider}.webhook_received`, and the
 * payload-only `ap.ecommerce.payment.webhookReceived` from the hooks
 * spec — so downstream satellites can subscribe to a single provider or
 * every provider at once. An unverified request never dispatches.
 *
 * Engine spec §4.2.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers;

use ArtisanPackUI\Ecommerce\Jobs\ReconcilePaymentSession;
use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use ArtisanPackUI\Ecommerce\Models\InboundWebhookDelivery;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\RateLimiting\EcommerceRateLimiter;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class WebhookController
{
    /**
     * @since 1.0.0
     *
     * @param  PaymentGatewayRegistry  $gateways  Payment gateway registry.
     */
    public function __construct( private readonly PaymentGatewayRegistry $gateways )
    {
    }

    /**
     * Handles an inbound provider webhook.
     *
     * @since 1.0.0
     *
     * @param  Request  $request   Inbound webhook request.
     * @param  string   $provider  Gateway registry key from the URL (`{provider}`).
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Receive a payment-provider webhook', description: 'Dispatched to the payment gateway registered under `{provider}`, which verifies the provider signature. Unknown providers are a 404 and bodies over webhooks.inbound_max_bytes a 413; neither is stored. Unverified requests are a 400.' )]
    public function handle( Request $request, string $provider ): JsonResponse
    {
        $gateway = $this->gateways->find( $provider );

        // Unknown providers are refused without a ledger row: the segment is
        // free text, so storing these would let anyone fill the table (G1).
        if ( null === $gateway ) {
            Log::channel( 'ecommerce' )->warning( 'Rejected inbound webhook for unregistered provider.', [
                'provider' => mb_substr( $provider, 0, 60 ),
                'ip'       => $request->ip(),
            ] );

            return new JsonResponse(
                [ 'code' => 'gateway_not_registered', 'message' => __( 'Payment gateway ":provider" is not registered.', [ 'provider' => mb_substr( $provider, 0, 60 ) ] ) ],
                404,
            );
        }

        $maxBytes = max( 1, (int) config( 'artisanpack.ecommerce.webhooks.inbound_max_bytes', 524_288 ) );

        if ( (int) $request->headers->get( 'Content-Length', '0' ) > $maxBytes || strlen( (string) $request->getContent() ) > $maxBytes ) {
            Log::channel( 'ecommerce' )->warning( 'Rejected oversized inbound webhook.', [
                'provider' => $provider,
                'ip'       => $request->ip(),
            ] );

            return new JsonResponse(
                [ 'code' => 'payload_too_large', 'message' => __( 'The webhook body is larger than :bytes bytes.', [ 'bytes' => $maxBytes ] ) ],
                413,
            );
        }

        $result = $gateway->handleWebhook( $request );

        if ( ! $result->verified ) {
            Log::channel( 'ecommerce' )->warning( 'Rejected unverified inbound webhook.', [
                'provider' => $provider,
                'code'     => $result->errorCode,
                'message'  => $result->errorMessage,
            ] );

            $this->ledger( $request, $provider, $result, 400, false );

            return new JsonResponse(
                [ 'code' => $result->errorCode, 'message' => $result->errorMessage ],
                400,
            );
        }

        // Verified deliveries count against the provider's own allowance,
        // which unverified traffic never touches.
        $limiter  = app( EcommerceRateLimiter::class );
        $exceeded = $limiter->exceeded( 'ecommerce.webhook.verified', $request );

        if ( null !== $exceeded ) {
            return new JsonResponse(
                [ 'code' => 'rate_limited', 'message' => __( 'Too many webhook deliveries. Try again later.' ) ],
                429,
                [ 'Retry-After' => (string) $exceeded['retry_after'] ],
            );
        }

        $limiter->hit( 'ecommerce.webhook.verified', $request );

        // Providers redeliver at-least-once. Atomically claim the event id
        // in `idempotency_records` before dispatching so a redelivery of the
        // same event never fires downstream listeners twice — the DB unique
        // index (actor_scope, endpoint_key, idempotency_key) is the single
        // source of truth. Engine spec §3.31.
        $duplicate = null !== $result->eventId && ! $this->claimEvent( $provider, $result->eventId, $request );

        $this->ledger( $request, $provider, $result, 200, $duplicate );

        if ( $duplicate ) {
            return new JsonResponse(
                [ 'received' => true, 'event_id' => $result->eventId, 'type' => $result->eventType, 'duplicate' => true ],
                200,
            );
        }

        if ( function_exists( 'doAction' ) ) {
            try {
                doAction( 'ap.ecommerce.webhook_received', $provider, $result, $request );
                doAction( sprintf( 'ap.ecommerce.gateway.%s.webhook_received', $provider ), $result, $request );
                doAction( 'ap.ecommerce.payment.webhookReceived', $result->payload, $provider );

                // A payment outcome settles its checkout (a shopper who closed
                // the tab still gets their order), off the request (#168).
                if ( $result->hasPaymentOutcome() && null !== $result->sessionReference ) {
                    ReconcilePaymentSession::dispatch( $provider, $result->sessionReference, (string) $result->outcome );
                }
            } catch ( Throwable $e ) {
                // A listener failed part-way, so not every hook ran. Release
                // the claim so the provider's retry dispatches again instead
                // of being swallowed as a duplicate (at-least-once delivery),
                // then let the error surface as a 5xx that triggers the retry.
                if ( null !== $result->eventId ) {
                    $this->releaseEvent( $provider, $result->eventId );
                }

                throw $e;
            }
        }

        return new JsonResponse(
            [ 'received' => true, 'event_id' => $result->eventId, 'type' => $result->eventType ],
            200,
        );
    }

    /**
     * Claims `$eventId` under the provider's scope so a redelivery of the
     * same event returns `200` without dispatching a second time.
     *
     * Returns `true` when the row was newly inserted (safe to dispatch),
     * `false` when a row with the same key already existed. A storage
     * error is logged and the caller is allowed through: dropping a
     * legitimate event because the dedupe table is unavailable is worse
     * than the small chance of a duplicate side effect in an already-
     * degraded state.
     *
     * @since 1.0.0
     *
     * @param  string   $provider  Gateway registry key.
     * @param  string   $eventId   Provider event id.
     * @param  Request  $request   Inbound request, used for observability.
     *
     * @return bool
     */
    private function claimEvent( string $provider, string $eventId, Request $request ): bool
    {
        try {
            IdempotencyRecord::query()->create( [
                'actor_scope'     => 'gateway.' . $provider,
                'endpoint_key'    => 'webhook.' . $provider,
                'idempotency_key' => $eventId,
                'request_hash'    => hash( 'sha256', (string) $request->getContent() ),
                'response_status' => 200,
                'expires_at'      => Carbon::now()->addDays( 30 ),
            ] );

            return true;
        } catch ( QueryException $e ) {
            // Duplicate-key violation on the (actor_scope, endpoint_key,
            // idempotency_key) unique index — we've already processed this
            // event id at least once.
            if ( '23000' === (string) $e->getCode() || '23505' === (string) $e->getCode() ) {
                return false;
            }

            Log::channel( 'ecommerce' )->error( 'Failed to claim inbound webhook event id; allowing dispatch through.', [
                'provider' => $provider,
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ] );

            return true;
        } catch ( Throwable $e ) {
            Log::channel( 'ecommerce' )->error( 'Failed to claim inbound webhook event id; allowing dispatch through.', [
                'provider' => $provider,
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ] );

            return true;
        }
    }

    /**
     * Releases a claim taken by {@see self::claimEvent()} after dispatch
     * failed, so the provider's redelivery of `$eventId` is dispatched
     * again. A storage error is logged; the redelivery is then treated as
     * a duplicate, which is the behaviour before this release existed.
     *
     * @since 1.0.0
     *
     * @param  string  $provider  Gateway registry key.
     * @param  string  $eventId   Provider event id.
     *
     * @return void
     */
    private function releaseEvent( string $provider, string $eventId ): void
    {
        try {
            IdempotencyRecord::query()
                ->where( 'actor_scope', 'gateway.' . $provider )
                ->where( 'endpoint_key', 'webhook.' . $provider )
                ->where( 'idempotency_key', $eventId )
                ->delete();
        } catch ( Throwable $e ) {
            Log::channel( 'ecommerce' )->error( 'Failed to release inbound webhook event id after a dispatch failure.', [
                'provider' => $provider,
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ] );
        }
    }

    /**
     * Writes a delivery row to the audit ledger.
     *
     * The ledger is best-effort — a storage failure is logged and the
     * request continues (returning the response the gateway asked for)
     * rather than turning every ledger outage into an outage for the
     * provider.
     *
     * @since 1.0.0
     *
     * @param  Request        $request         Inbound request.
     * @param  string         $provider        Gateway registry key.
     * @param  WebhookResult  $result          Gateway result.
     * @param  int            $responseStatus  HTTP status we responded with.
     * @param  bool           $duplicate       Whether this delivery was a replay.
     *
     * @return void
     */
    private function ledger( Request $request, string $provider, WebhookResult $result, int $responseStatus, bool $duplicate ): void
    {
        try {
            $body = (string) $request->getContent();

            // An unverified body is untrusted and may be junk: keep enough
            // to diagnose a misconfigured secret, not the whole thing.
            $stored = $result->verified ? $body : mb_strcut( $body, 0, InboundWebhookDelivery::UNVERIFIED_PAYLOAD_BYTES );

            InboundWebhookDelivery::query()->create( [
                'provider'          => $provider,
                'event_id'          => $result->eventId,
                'event_type'        => $result->eventType,
                'verified'          => $result->verified,
                'duplicate'         => $duplicate,
                'error_code'        => $result->errorCode,
                'session_reference' => $result->verified ? $result->sessionReference : null,
                'payload_hash'      => hash( 'sha256', $body ),
                'payload_size'      => strlen( $body ),
                'payload_truncated' => strlen( $stored ) < strlen( $body ),
                'payload'           => $stored,
                'parsed'            => $result->verified ? $result->payload : null,
                'response_status'   => $responseStatus,
                'correlation_id'    => $request->headers->get( 'X-Request-Id' ),
                'received_at'       => Carbon::now(),
            ] );
        } catch ( Throwable $e ) {
            Log::channel( 'ecommerce' )->error( 'Failed to write inbound webhook delivery to ledger.', [
                'provider' => $provider,
                'error'    => $e->getMessage(),
            ] );
        }
    }
}
