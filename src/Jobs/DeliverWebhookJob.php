<?php

/**
 * DeliverWebhookJob.
 *
 * Queued wrapper around {@see WebhookDeliveryService::attempt()} for one
 * `webhook_deliveries` row (engine spec §8.2 step 4). The job itself never
 * retries — the ledger's `next_retry_at` + the
 * `ecommerce:retry-webhook-deliveries` sweep own the backoff schedule, so
 * a failed attempt must not also be retried by the queue worker.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Jobs;

use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Services\WebhookDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * The ledger owns retries.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * @since 1.0.0
     *
     * @param  int          $deliveryId  `webhook_deliveries.id`.
     * @param  string|null  $claim       The `next_retry_at` value this job was queued
     *                                   under. If the row has been re-claimed (or already
     *                                   attempted) since, this job is stale and skips.
     */
    public function __construct(
        public readonly int $deliveryId,
        public readonly ?string $claim = null,
    ) {
        $this->onConnection( config( 'artisanpack.ecommerce.webhooks.connection' ) );
        $this->onQueue( config( 'artisanpack.ecommerce.webhooks.queue' ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  WebhookDeliveryService  $deliveries  Delivery service.
     *
     * @return void
     */
    public function handle( WebhookDeliveryService $deliveries ): void
    {
        $delivery = WebhookDelivery::query()->find( $this->deliveryId );

        if ( null !== $delivery ) {
            $deliveries->attempt( $delivery, $this->claim );
        }
    }
}
