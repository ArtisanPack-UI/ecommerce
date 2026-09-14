<?php

/**
 * InventoryService.
 *
 * Encapsulates the three write paths against the inventory tables:
 * stock adjustments, checkout reservations, and expired-reservation release.
 *
 * Effective available on any {@see InventoryItem} is
 * `quantity_on_hand - quantity_reserved`. Reservations increment
 * `quantity_reserved`; expired-release decrements it back. Adjustments
 * change `quantity_on_hand` directly (e.g. shipment out, stock intake,
 * returns reconciliation).
 *
 * Engine spec §3.11, §3.12, §6.10, §11.6.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Exceptions\InsufficientStockException;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class InventoryService
{
    /**
     * @since 1.0.0
     *
     * @param  ConfigRepository  $config  Injected so callers can override the
     *                                    reservation TTL per-app.
     */
    public function __construct( protected ConfigRepository $config )
    {
    }

    /**
     * Adjusts on-hand stock for an inventory row.
     *
     * `$delta` may be negative (e.g. shipment out) or positive (e.g. intake).
     * Fires `ap.ecommerce.inventory.adjusting` (filter) before persist so
     * listeners can veto or modify the delta, and
     * `ap.ecommerce.inventory.adjusted` (action) after. Low-stock and
     * out-of-stock hooks fire when the resulting on-hand crosses thresholds
     * downward.
     *
     * @since 1.0.0
     *
     * @param  InventoryItem  $item    Row to adjust.
     * @param  int            $delta   Signed change in `quantity_on_hand`.
     * @param  string         $reason  Free-form audit reason.
     *
     * @return InventoryItem The refreshed row.
     */
    public function adjust( InventoryItem $item, int $delta, string $reason ): InventoryItem
    {
        $delta = (int) applyFilters( 'ap.ecommerce.inventory.adjusting', $delta, $item, $reason );

        if ( 0 === $delta ) {
            return $item->fresh() ?? $item;
        }

        return DB::transaction( function () use ( $item, $delta ): InventoryItem {
            $fresh = InventoryItem::query()->lockForUpdate()->findOrFail( $item->id );

            $previousOnHand          = $fresh->quantity_on_hand;
            $newOnHand               = $previousOnHand + $delta;
            $fresh->quantity_on_hand = $newOnHand;
            $fresh->save();

            doAction( 'ap.ecommerce.inventory.adjusted', $fresh, $delta, $newOnHand );

            $this->fireStockThresholdHooks( $fresh, $previousOnHand, $newOnHand );

            return $fresh;
        } );
    }

    /**
     * Reserves `$quantity` of an inventory row for a Cart or Order.
     *
     * Runs inside a transaction with a row-level lock to prevent two
     * concurrent checkouts from both winning the last unit. When the row is
     * untracked or backorderable, the reservation is always created.
     * Otherwise, if effective available is less than `$quantity`, throws
     * {@see InsufficientStockException}.
     *
     * TTL comes from `artisanpack.ecommerce.checkout.reservation_ttl_minutes`
     * (default 15) unless overridden via `$expiresAt`.
     *
     * @since 1.0.0
     *
     * @param  InventoryItem  $item        Row to reserve against.
     * @param  Model          $reservable  Cart or Order the reservation belongs to.
     * @param  int            $quantity    Units to reserve. Must be > 0.
     * @param  Carbon|null    $expiresAt   Optional explicit expiry.
     *
     * @throws InsufficientStockException When effective available is too low.
     *
     * @return InventoryReservation
     */
    public function reserve(
        InventoryItem $item,
        Model $reservable,
        int $quantity,
        ?Carbon $expiresAt = null,
    ): InventoryReservation {
        if ( $quantity < 1 ) {
            throw new InvalidArgumentException( 'Reservation quantity must be at least 1.' );
        }

        $expiresAt ??= Carbon::now()->addMinutes( $this->reservationTtlMinutes() );

        return DB::transaction( function () use ( $item, $reservable, $quantity, $expiresAt ): InventoryReservation {
            $fresh = InventoryItem::query()->lockForUpdate()->findOrFail( $item->id );

            if ( $fresh->track_inventory && ! $fresh->allow_backorder ) {
                $available = $fresh->availableQuantity();
                if ( $available < $quantity ) {
                    throw new InsufficientStockException( $fresh, $quantity, $available );
                }
            }

            $reservation = new InventoryReservation( [
                'inventory_item_id' => $fresh->id,
                'reservable_type'   => $reservable->getMorphClass(),
                'reservable_id'     => $reservable->getKey(),
                'quantity'          => $quantity,
                'expires_at'        => $expiresAt,
            ] );
            $reservation->save();

            $fresh->quantity_reserved = $fresh->quantity_reserved + $quantity;
            $fresh->save();

            doAction( 'ap.ecommerce.inventory.reserved', $reservation );

            return $reservation;
        } );
    }

    /**
     * Releases all reservations whose `expires_at <= now`.
     *
     * For each expired row: decrements the owning item's `quantity_reserved`
     * (never below zero), deletes the reservation, and fires
     * `ap.ecommerce.inventory.reservationReleased`.
     *
     * @since 1.0.0
     *
     * @return int Count of reservations released.
     */
    public function releaseExpired(): int
    {
        $released = 0;

        InventoryReservation::query()
            ->expired()
            ->orderBy( 'id' )
            ->chunkById( 100, function ( $reservations ) use ( &$released ): void {
                foreach ( $reservations as $reservation ) {
                    DB::transaction( function () use ( $reservation, &$released ): void {
                        $item = InventoryItem::query()
                            ->lockForUpdate()
                            ->find( $reservation->inventory_item_id );

                        if ( null !== $item ) {
                            $item->quantity_reserved = max(
                                0,
                                $item->quantity_reserved - $reservation->quantity,
                            );
                            $item->save();
                        }

                        $snapshot = clone $reservation;
                        $reservation->delete();

                        doAction( 'ap.ecommerce.inventory.reservationReleased', $snapshot );
                        ++$released;
                    } );
                }
            } );

        return $released;
    }

    /**
     * Resolves the reservation TTL from config, defaulting to 15 minutes.
     *
     * @since 1.0.0
     *
     * @return int
     */
    protected function reservationTtlMinutes(): int
    {
        $ttl = (int) $this->config->get(
            'artisanpack.ecommerce.checkout.reservation_ttl_minutes',
            15,
        );

        return $ttl > 0 ? $ttl : 15;
    }

    /**
     * Fires the low-stock and out-of-stock hooks when a downward adjustment
     * crosses the corresponding thresholds.
     *
     * @since 1.0.0
     *
     * @param  InventoryItem  $item             Row after adjustment.
     * @param  int            $previousOnHand   `quantity_on_hand` before the adjustment.
     * @param  int            $newOnHand        `quantity_on_hand` after the adjustment.
     *
     * @return void
     */
    protected function fireStockThresholdHooks( InventoryItem $item, int $previousOnHand, int $newOnHand ): void
    {
        if ( $newOnHand >= $previousOnHand ) {
            return;
        }

        $threshold = $item->low_stock_threshold;
        if ( null !== $threshold && $previousOnHand > $threshold && $newOnHand <= $threshold ) {
            doAction( 'ap.ecommerce.inventory.lowStock', $item, $newOnHand );
        }

        if ( $previousOnHand > 0 && $newOnHand <= 0 ) {
            doAction( 'ap.ecommerce.inventory.outOfStock', $item );
        }
    }
}
