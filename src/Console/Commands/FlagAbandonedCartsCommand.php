<?php

/**
 * FlagAbandonedCartsCommand.
 *
 * `ecommerce:flag-abandoned-carts` (engine spec §11.6; parent plan §7.3):
 * marks carts abandoned when checkout started, an email is known, the cart
 * didn't become an order or expire, and nothing changed for
 * `cart.abandoned_after_minutes`. Each cart is flagged once — its
 * `abandoned_at` is set atomically — and fires `ap.ecommerce.cart.abandoned`
 * and {@see CartAbandoned}. Any later change to the cart clears the flag.
 * Scheduled every 5 minutes.
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

use ArtisanPackUI\Ecommerce\Events\CartAbandoned;
use ArtisanPackUI\Ecommerce\Models\Cart;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class FlagAbandonedCartsCommand extends Command
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'ecommerce:flag-abandoned-carts';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Flag carts left in checkout (with an email) as abandoned and fire CartAbandoned once for each.';

    /**
     * @since 1.0.0
     *
     * @return int
     */
    public function handle(): int
    {
        $minutes = (int) ecommerceSetting( 'cart.abandoned_after_minutes', 60 );
        $now     = Carbon::now();
        $cutoff  = $now->copy()->subMinutes( max( 1, $minutes ) );
        $flagged = 0;

        Cart::query()
            ->whereNotNull( 'checkout_started_at' )
            ->whereNull( 'completed_order_id' )
            ->whereNull( 'abandoned_at' )
            ->whereNotNull( 'email' )
            ->where( 'updated_at', '<', $cutoff )
            ->where( fn ( $query ) => $query->whereNull( 'expires_at' )->orWhere( 'expires_at', '>', $now ) )
            ->select( [ 'id' ] )
            ->chunkById( 100, function ( $carts ) use ( $now, $cutoff, &$flagged ): void {
                foreach ( $carts as $row ) {
                    // Only the update that flips abandoned_at fires, so a
                    // second sweep (or an overlapping one) never repeats it,
                    // and a cart touched since it was read isn't flagged.
                    $won = Cart::query()
                        ->whereKey( $row->id )
                        ->whereNull( 'abandoned_at' )
                        ->whereNull( 'completed_order_id' )
                        ->where( 'updated_at', '<', $cutoff )
                        ->toBase()
                        ->update( [ 'abandoned_at' => $now ] );

                    if ( 1 !== $won ) {
                        continue;
                    }

                    $cart = Cart::query()->find( $row->id );

                    if ( null === $cart ) {
                        continue;
                    }

                    doAction( 'ap.ecommerce.cart.abandoned', $cart );
                    Event::dispatch( new CartAbandoned( $cart ) );

                    ++$flagged;
                }
            } );

        $this->info( sprintf( 'Flagged %d abandoned cart(s).', $flagged ) );

        return self::SUCCESS;
    }
}
