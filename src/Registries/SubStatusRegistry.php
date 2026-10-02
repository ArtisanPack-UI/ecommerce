<?php

/**
 * SubStatusRegistry.
 *
 * Cached lookup of order sub-statuses (engine spec §5 row 14, §3.17). The
 * rows are read once, cached forever under {@see self::CACHE_KEY}, and
 * memoised per process; {@see \ArtisanPackUI\Ecommerce\Services\OrderSubstatusService}
 * and the {@see OrderSubstatus} model's saved / deleted events call
 * {@see self::flush()} so the cache never outlives a write.
 *
 * Sub-statuses are ordered by system status (the order of
 * {@see OrderStatusMachine::ALLOWED_TRANSITIONS}; unknown system statuses
 * sort last), then `position`, then id.
 *
 * Bound as a singleton in
 * {@see \ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Registries;

use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Services\OrderStatusMachine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SubStatusRegistry
{
    /**
     * Cache key holding the raw sub-status rows.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CACHE_KEY = 'ap.ecommerce.order_substatuses';

    /**
     * Per-process copy of the rows.
     *
     * @since 1.0.0
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $rows = null;

    /**
     * Every sub-status, ordered by system status then position.
     *
     * @since 1.0.0
     *
     * @return Collection<int, OrderSubstatus>
     */
    public function all(): Collection
    {
        return OrderSubstatus::hydrate( $this->rows() )->toBase();
    }

    /**
     * The sub-statuses of one system status, in position order.
     *
     * @since 1.0.0
     *
     * @param  string  $systemStatus  System status (`pending`, `processing`, …).
     *
     * @return Collection<int, OrderSubstatus>
     */
    public function forSystemStatus( string $systemStatus ): Collection
    {
        return $this->all()
            ->filter( static fn ( OrderSubstatus $substatus ): bool => $systemStatus === (string) $substatus->system_status )
            ->values();
    }

    /**
     * One sub-status by id, or by key.
     *
     * Keys are only unique per system status, so a key lookup without
     * `$systemStatus` returns the first match in registry order.
     *
     * @since 1.0.0
     *
     * @param  int|string   $idOrKey       Id (an int or a digit-only string), or the sub-status key.
     * @param  string|null  $systemStatus  Narrows a key lookup to one system status.
     *
     * @return OrderSubstatus|null
     */
    public function get( int|string $idOrKey, ?string $systemStatus = null ): ?OrderSubstatus
    {
        return $this->all()->first( static function ( OrderSubstatus $substatus ) use ( $idOrKey, $systemStatus ): bool {
            if ( null !== $systemStatus && $systemStatus !== (string) $substatus->system_status ) {
                return false;
            }

            return is_int( $idOrKey ) || ctype_digit( $idOrKey )
                ? (int) $idOrKey === (int) $substatus->id
                : $idOrKey === (string) $substatus->key;
        } );
    }

    /**
     * Forgets the cached rows so the next lookup reads the table again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function flush(): void
    {
        $this->forget();

        // A lookup made before the write commits could cache the old rows,
        // so forget them again once the outermost transaction commits.
        if ( self::inTransaction() ) {
            DB::afterCommit( fn () => $this->forget() );
        }
    }

    /**
     * The raw rows, from memory, the cache, or the table.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rows(): array
    {
        if ( null !== $this->rows ) {
            return $this->rows;
        }

        try {
            $cached = Cache::get( self::CACHE_KEY );

            if ( is_array( $cached ) ) {
                return $this->rows = $cached;
            }
        } catch ( Throwable ) {
            // Fall through to the database.
        }

        $order = array_flip( array_keys( OrderStatusMachine::ALLOWED_TRANSITIONS ) );

        $rows = OrderSubstatus::query()
            ->orderBy( 'position' )
            ->orderBy( 'id' )
            ->get()
            ->sortBy( static fn ( OrderSubstatus $substatus ): int => $order[ (string) $substatus->system_status ] ?? PHP_INT_MAX )
            ->map( static fn ( OrderSubstatus $substatus ): array => $substatus->getAttributes() )
            ->values()
            ->all();

        // Rows read inside a transaction may be rolled back, so they are
        // served but not remembered.
        if ( self::inTransaction() ) {
            return $rows;
        }

        try {
            Cache::forever( self::CACHE_KEY, $rows );
        } catch ( Throwable ) {
            // Serve from the database until the cache is back.
        }

        return $this->rows = $rows;
    }

    /**
     * Whether a transaction is open whose commit or rollback could change
     * the rows (the transaction a test wraps around each case does not count).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    protected static function inTransaction(): bool
    {
        if ( app()->bound( 'db.transactions' ) ) {
            return app( 'db.transactions' )->callbackApplicableTransactions()->isNotEmpty();
        }

        return DB::transactionLevel() > 0;
    }

    /**
     * Forgets the rows in memory and in the cache.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function forget(): void
    {
        $this->rows = null;

        try {
            Cache::forget( self::CACHE_KEY );
        } catch ( Throwable ) {
            // Nothing cached to forget.
        }
    }
}
