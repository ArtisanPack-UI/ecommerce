<?php

/**
 * PruneLedgersCommand.
 *
 * `ecommerce:prune-ledgers` (audit G2): deletes ledger rows older than their
 * `retention.*_days` — inbound provider webhooks, finished outbound webhook
 * deliveries (delivered, or out of attempts), activity entries, and digital
 * download events. A retention of 0 keeps that ledger forever. Deletes in
 * batches so a large backlog doesn't hold one long lock. Scheduled daily.
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

use ArtisanPackUI\Ecommerce\Models\ActivityLogEntry;
use ArtisanPackUI\Ecommerce\Models\DigitalDownloadEvent;
use ArtisanPackUI\Ecommerce\Models\InboundWebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PruneLedgersCommand extends Command
{
    /**
     * Rows deleted per statement.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const BATCH = 1_000;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'ecommerce:prune-ledgers';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Delete inbound webhooks, finished webhook deliveries, activity entries, and download events older than their retention.';

    /**
     * @since 1.0.0
     *
     * @return int
     */
    public function handle(): int
    {
        $maxAttempts = max( 1, (int) config( 'artisanpack.ecommerce.webhooks.max_attempts', 10 ) );

        $ledgers = [
            'inbound webhook(s)'  => [ 'inbound_webhooks_days', static fn (): Builder => InboundWebhookDelivery::query(), 'received_at' ],
            'webhook delivery(s)' => [ 'webhook_deliveries_days', static fn (): Builder => WebhookDelivery::query()->where( fn ( Builder $finished ) => $finished
                ->whereNotNull( 'delivered_at' )
                ->orWhere( fn ( Builder $exhausted ) => $exhausted->whereNull( 'next_retry_at' )->where( 'attempts', '>=', $maxAttempts ) ) ), 'created_at' ],
            'activity entry(s)'   => [ 'activity_log_days', static fn (): Builder => ActivityLogEntry::query(), 'created_at' ],
            'download event(s)'   => [ 'download_events_days', static fn (): Builder => DigitalDownloadEvent::query(), 'created_at' ],
        ];

        foreach ( $ledgers as $label => [ $setting, $query, $column ] ) {
            $days = (int) config( 'artisanpack.ecommerce.retention.' . $setting, 0 );

            if ( $days <= 0 ) {
                continue;
            }

            $this->info( sprintf( 'Pruned %d %s.', $this->prune( $query, $column, Carbon::now()->subDays( $days ) ), $label ) );
        }

        return self::SUCCESS;
    }

    /**
     * Deletes the rows `$query` matches whose `$column` is before `$cutoff`,
     * {@see self::BATCH} at a time.
     *
     * @since 1.0.0
     *
     * @param  Closure(): Builder  $query   Fresh query for the ledger.
     * @param  string              $column  Timestamp column.
     * @param  Carbon              $cutoff  Oldest kept time.
     *
     * @return int
     */
    protected function prune( Closure $query, string $column, Carbon $cutoff ): int
    {
        $pruned = 0;

        do {
            $ids = $query()->where( $column, '<', $cutoff )->orderBy( 'id' )->limit( self::BATCH )->pluck( 'id' );

            if ( $ids->isEmpty() ) {
                break;
            }

            // The base builder: append-only ledgers refuse Eloquent deletes,
            // and retention is the sanctioned way their rows go.
            $pruned += $query()->whereKey( $ids->all() )->toBase()->delete();
        } while ( self::BATCH === $ids->count() );

        return $pruned;
    }
}
