<?php

/**
 * StripeWebhookController.
 *
 * Receives inbound Stripe webhooks and delegates to {@see StripeGateway::handleWebhook()}
 * for signature verification. A verified event is dispatched via the
 * `ap.ecommerce.gateway.stripe.webhook_received` action so satellites and
 * host applications can act on it (mark orders paid, trigger fulfillment,
 * etc.) without the engine coupling itself to any specific event type.
 *
 * Unverified requests return `400` with a `code`-tagged JSON body and never
 * dispatch anything — a spoofed request cannot trigger downstream events.
 *
 * Engine spec §4.2, parent plan §8.1.
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

use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeGateway;
use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
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
class StripeWebhookController
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
     * Handles a POST from Stripe's webhook infrastructure.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Inbound webhook request.
     *
     * @return JsonResponse
     */
    public function __invoke( Request $request ): JsonResponse
    {
        $gateway = $this->gateways->find( StripeGateway::KEY );

        if ( ! $gateway instanceof StripeGateway ) {
            return new JsonResponse(
                [ 'code' => 'stripe_gateway_not_registered', 'message' => 'Stripe gateway is not registered.' ],
                503,
            );
        }

        $result = $gateway->handleWebhook( $request );

        if ( ! $result->verified ) {
            Log::channel( 'ecommerce' )->warning( 'Rejected unverified Stripe webhook.', [
                'code'    => $result->errorCode,
                'message' => $result->errorMessage,
            ] );

            return new JsonResponse(
                [ 'code' => $result->errorCode, 'message' => $result->errorMessage ],
                400,
            );
        }

        // Stripe redelivers events at-least-once. Atomically claim the event
        // id in `idempotency_records` before dispatching so a redelivery of
        // the same event never fires downstream listeners twice — the DB
        // unique index (actor_scope, endpoint_key, idempotency_key) is the
        // single source of truth. Engine spec §3.31.
        if ( null !== $result->eventId && ! $this->claimEvent( $result->eventId, $request ) ) {
            return new JsonResponse(
                [ 'received' => true, 'event_id' => $result->eventId, 'type' => $result->eventType, 'duplicate' => true ],
                200,
            );
        }

        if ( function_exists( 'doAction' ) ) {
            doAction( 'ap.ecommerce.gateway.stripe.webhook_received', $result, $request );
        }

        return new JsonResponse(
            [ 'received' => true, 'event_id' => $result->eventId, 'type' => $result->eventType ],
            200,
        );
    }

    /**
     * Claims `$eventId` in the shared `idempotency_records` table so a
     * redelivery of the same Stripe event returns `200` without dispatching
     * the downstream action a second time.
     *
     * Returns `true` when the row was newly inserted (safe to dispatch),
     * `false` when a row with the same key already existed (duplicate
     * delivery — skip dispatch). A storage error is logged and the caller
     * is allowed through: dropping a legitimate Stripe event because the
     * dedupe table is unavailable is worse than the small chance of a
     * duplicate side effect in an already-degraded state.
     *
     * @since 1.0.0
     *
     * @param  string   $eventId  Stripe event id (`evt_…`).
     * @param  Request  $request  Inbound request, used for observability.
     *
     * @return bool
     */
    private function claimEvent( string $eventId, Request $request ): bool
    {
        try {
            IdempotencyRecord::query()->create( [
                'actor_scope'     => 'gateway.stripe',
                'endpoint_key'    => 'webhook.stripe',
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

            Log::channel( 'ecommerce' )->error( 'Failed to claim Stripe webhook event id; allowing dispatch through.', [
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ] );

            return true;
        } catch ( Throwable $e ) {
            Log::channel( 'ecommerce' )->error( 'Failed to claim Stripe webhook event id; allowing dispatch through.', [
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ] );

            return true;
        }
    }
}
