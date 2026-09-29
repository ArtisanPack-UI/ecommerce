<?php

/**
 * DispatchWebhooksForEvent.
 *
 * Bridges the engine's domain events (engine spec §7) to outbound
 * webhooks (§8.2 step 2). Registered for every event class listed in
 * `artisanpack.ecommerce.webhooks.events`; the wire name is derived from
 * the class (`OrderRefunded` → `order.refunded`) and the data from the
 * event's public properties via {@see WebhookPayloadFactory}.
 *
 * The payload is captured when the event fires, but the fan-out (ledger
 * rows + queued jobs) runs after the surrounding database transaction
 * commits, and a failure in it is reported rather than thrown: a webhook
 * problem must never roll back the state change that caused it (e.g. a
 * refund the gateway has already settled). The HTTP happens in
 * {@see \ArtisanPackUI\Ecommerce\Jobs\DeliverWebhookJob}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Listeners;

use ArtisanPackUI\Ecommerce\Services\WebhookDispatcher;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookPayloadFactory;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DispatchWebhooksForEvent
{
    /**
     * @since 1.0.0
     *
     * @param  WebhookDispatcher      $dispatcher  Fan-out service.
     * @param  WebhookPayloadFactory  $payloads    Event serializer.
     */
    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly WebhookPayloadFactory $payloads,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @param  object  $event  Domain event.
     *
     * @return void
     */
    public function handle( object $event ): void
    {
        try {
            $name = WebhookPayloadFactory::eventName( $event );
            $data = $this->payloads->fromEvent( $event );
        } catch ( Throwable $exception ) {
            report( $exception );

            return;
        }

        DB::afterCommit( function () use ( $name, $data ): void {
            try {
                $this->dispatcher->dispatch( $name, $data );
            } catch ( Throwable $exception ) {
                report( $exception );
            }
        } );
    }
}
