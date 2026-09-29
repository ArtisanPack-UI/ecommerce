<?php

/**
 * RetryWebhookDeliveriesCommand.
 *
 * Picks up `webhook_deliveries` rows whose `next_retry_at` has passed and
 * queues a delivery attempt for each (engine spec §11.6). Runs every
 * minute. Each row is claimed by pushing `next_retry_at` forward before
 * its job is queued, so the next sweep can't queue it a second time while
 * the first attempt is still pending.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Console\Commands;

use ArtisanPackUI\Ecommerce\Jobs\DeliverWebhookJob;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Services\WebhookDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RetryWebhookDeliveriesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ecommerce:retry-webhook-deliveries {--limit=500 : Maximum deliveries to queue in one run}';

    /**
     * @var string
     */
    protected $description = 'Queue outbound webhook deliveries whose retry time has come.';

    /**
     * @since 1.0.0
     *
     * @return int
     */
    public function handle(): int
    {
        $now   = Carbon::now();
        $claim = $now->copy()->addSeconds( WebhookDispatcher::claimSeconds() )->startOfSecond();
        $count = 0;

        $due = WebhookDelivery::query()
            ->due( $now )
            ->orderBy( 'next_retry_at' )
            ->limit( max( 1, (int) $this->option( 'limit' ) ) )
            ->toBase()
            ->pluck( 'next_retry_at', 'id' );

        foreach ( $due as $id => $retryAt ) {
            // Compare-and-set on the timestamp we read: only the sweep that
            // moves it wins the row.
            $claimed = WebhookDelivery::query()
                ->whereKey( $id )
                ->whereNull( 'delivered_at' )
                ->where( 'next_retry_at', $retryAt )
                ->update( [ 'next_retry_at' => $claim ] );

            if ( 1 === $claimed ) {
                DeliverWebhookJob::dispatch( (int) $id, $claim->toDateTimeString() );
                $count++;
            }
        }

        $this->info( sprintf( 'Queued %d webhook delivery retr%s.', $count, 1 === $count ? 'y' : 'ies' ) );

        return self::SUCCESS;
    }
}
