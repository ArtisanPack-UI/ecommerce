<?php

/**
 * PruneCartsCommand.
 *
 * `ecommerce:prune-carts` (audit D7): deletes carts that can no longer be
 * used — expired ones, and converted ones once `cart.ttl_days` have passed
 * (a converted cart is kept that long so a repeated finalize still finds
 * its order). Each cart's stock holds are released first. Pruning lets
 * products that sat in old carts be deleted. Scheduled daily.
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

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PruneCartsCommand extends Command
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'ecommerce:prune-carts';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Delete expired carts, and converted carts older than cart.ttl_days, releasing their stock holds.';

    /**
     * @since 1.0.0
     *
     * @param  InventoryService  $inventory  Releases holds.
     *
     * @return int
     */
    public function handle( InventoryService $inventory ): int
    {
        $now     = Carbon::now();
        $days    = max( 1, (int) config( 'artisanpack.ecommerce.cart.ttl_days', 30 ) );
        $pruned  = 0;

        Cart::query()
            ->where( fn ( $query ) => $query
                ->where( fn ( $expired ) => $expired->whereNull( 'completed_order_id' )->where( 'expires_at', '<', $now ) )
                ->orWhere( fn ( $converted ) => $converted->whereNotNull( 'completed_order_id' )->where( 'updated_at', '<', $now->copy()->subDays( $days ) ) ) )
            ->select( [ 'id' ] )
            ->chunkById( 100, function ( $carts ) use ( $inventory, &$pruned ): void {
                foreach ( $carts as $row ) {
                    DB::transaction( function () use ( $row, $inventory, &$pruned ): void {
                        $cart = Cart::query()->lockForUpdate()->find( $row->id );

                        if ( null === $cart ) {
                            return;
                        }

                        $inventory->releaseFor( $cart );
                        $cart->items()->delete();
                        $cart->delete();

                        ++$pruned;
                    } );
                }
            } );

        $this->info( sprintf( 'Pruned %d cart(s).', $pruned ) );

        return self::SUCCESS;
    }
}
