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
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

        if ( function_exists( 'doAction' ) ) {
            doAction( 'ap.ecommerce.gateway.stripe.webhook_received', $result, $request );
        }

        return new JsonResponse(
            [ 'received' => true, 'event_id' => $result->eventId, 'type' => $result->eventType ],
            200,
        );
    }
}
