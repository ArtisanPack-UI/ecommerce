<?php

/**
 * PruneIdempotencyRecordsCommand.
 *
 * Deletes `idempotency_records` rows whose `expires_at` has passed. Runs
 * hourly per engine spec §11.6.
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

use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PruneIdempotencyRecordsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ecommerce:prune-idempotency-records';

    /**
     * @var string
     */
    protected $description = 'Delete idempotency_records rows past their expires_at timestamp.';

    /**
     * @since 1.0.0
     *
     * @return int
     */
    public function handle(): int
    {
        $deleted = IdempotencyRecord::query()
            ->where( 'expires_at', '<', Carbon::now() )
            ->delete();

        $this->info( sprintf( 'Pruned %d expired idempotency record(s).', $deleted ) );

        return self::SUCCESS;
    }
}
