<?php

/**
 * WebhookController.
 *
 * Single inbound entry point for every payment provider registered in
 * {@see PaymentGatewayRegistry}. `POST /ecommerce/webhooks/{provider}`
 * resolves the gateway from `{provider}`, delegates signature verification
 * to its {@see \ArtisanPackUI\Ecommerce\Contracts\PaymentGateway::handleWebhook()}
 * implementation, dedupes replays via {@see IdempotencyRecord}, and
 * records the delivery (verified or not) in {@see InboundWebhookDelivery}
 * so operators can inspect and replay what came in.
 *
 * A verified event fans out to two hook names — the generic
 * `ap.ecommerce.webhook_received` and the provider-scoped
 * `ap.ecommerce.gateway.{provider}.webhook_received` — so downstream
 * satellites can subscribe to a single provider or every provider at
 * once. An unverified request is still ledgered but never dispatches.
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

use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use ArtisanPackUI\Ecommerce\Models\InboundWebhookDelivery;
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
    public function handle( Request $request, string $provider ): JsonResponse
    {
        $gateway = $this->gateways->find( $provider );

        if ( null === $gateway ) {
            Log::channel( 'ecommerce' )->warning( 'Rejected inbound webhook for unregistered provider.', [
                'provider' => $provider,
            ] );

            $this->ledger( $request, $provider, WebhookResult::unverified(
                'gateway_not_registered',
                sprintf( 'Payment gateway "%s" is not registered.', $provider ),
            ), 404, false );

            return new JsonResponse(
                [ 'code' => 'gateway_not_registered', 'message' => sprintf( 'Payment gateway "%s" is not registered.', $provider ) ],
                404,
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
            doAction( 'ap.ecommerce.webhook_received', $provider, $result, $request );
            doAction( sprintf( 'ap.ecommerce.gateway.%s.webhook_received', $provider ), $result, $request );
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

            InboundWebhookDelivery::query()->create( [
                'provider'        => $provider,
                'event_id'        => $result->eventId,
                'event_type'      => $result->eventType,
                'verified'        => $result->verified,
                'duplicate'       => $duplicate,
                'error_code'      => $result->errorCode,
                'payload_hash'    => hash( 'sha256', $body ),
                'payload'         => $body,
                'parsed'          => $result->verified ? $result->payload : null,
                'response_status' => $responseStatus,
                'correlation_id'  => $request->headers->get( 'X-Request-Id' ),
                'received_at'     => Carbon::now(),
            ] );
        } catch ( Throwable $e ) {
            Log::channel( 'ecommerce' )->error( 'Failed to write inbound webhook delivery to ledger.', [
                'provider' => $provider,
                'error'    => $e->getMessage(),
            ] );
        }
    }
}
