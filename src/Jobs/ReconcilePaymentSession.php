<?php

/**
 * ReconcilePaymentSession job.
 *
 * Queued by the inbound webhook controller when a verified gateway webhook
 * carries a payment outcome, so the provider gets its 200 straight away and
 * the checkout is settled by {@see PaymentReconciler} in the background
 * (#168). Dispatched after the webhook's ledger row commits.
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

use ArtisanPackUI\Ecommerce\Services\PaymentReconciler;
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
class ReconcilePaymentSession implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Attempts (a capture that hits a transient provider error is retried).
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Seconds between attempts.
     *
     * @since 1.0.0
     *
     * @var array<int, int>
     */
    public array $backoff = [ 30, 120 ];

    /**
     * @since 1.0.0
     *
     * @param  string  $gatewayKey  Gateway key.
     * @param  string  $reference   Session reference.
     * @param  string  $outcome     `WebhookResult::OUTCOME_*` value.
     */
    public function __construct(
        public readonly string $gatewayKey,
        public readonly string $reference,
        public readonly string $outcome,
    ) {
        $this->onConnection( config( 'artisanpack.ecommerce.webhooks.connection' ) );
        $this->onQueue( config( 'artisanpack.ecommerce.webhooks.queue' ) );
        $this->afterCommit();
    }

    /**
     * @since 1.0.0
     *
     * @param  PaymentReconciler  $reconciler  Reconciler.
     *
     * @return void
     */
    public function handle( PaymentReconciler $reconciler ): void
    {
        $reconciler->reconcile( $this->gatewayKey, $this->reference, $this->outcome );
    }
}
