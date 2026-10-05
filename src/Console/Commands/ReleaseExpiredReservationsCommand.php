<?php

/**
 * ReleaseExpiredReservationsCommand.
 *
 * Sweeps `inventory_reservations` whose `expires_at` is in the past,
 * decrementing each owning `inventory_items.quantity_reserved` and firing
 * `ap.ecommerce.inventory.reservationReleased` per row. Registered on the
 * scheduler at a one-minute cadence (engine spec §11.6).
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

use ArtisanPackUI\Ecommerce\Services\InventoryService;
use Illuminate\Console\Command;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ReleaseExpiredReservationsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ecommerce:release-expired-reservations';

    /**
     * @var string
     */
    protected $description = 'Delete inventory_reservations past their expires_at and free the reserved stock.';

    /**
     * @since 1.0.0
     *
     * @param  InventoryService  $inventory  Injected service that owns the sweep logic.
     *
     * @return int
     */
    public function handle( InventoryService $inventory ): int
    {
        $released = $inventory->releaseExpired();

        $this->info( sprintf( 'Released %d expired reservation(s).', $released ) );

        return self::SUCCESS;
    }
}
