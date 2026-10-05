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
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class InventoryService
{
    /**
     * Stock settings {@see self::updateSettings()} may change.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const SETTINGS = [
        'track_inventory',
        'allow_backorder',
        'low_stock_threshold',
    ];

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

        return DB::transaction( function () use ( $item, $delta, $reason ): InventoryItem {
            $fresh = InventoryItem::query()->lockForUpdate()->findOrFail( $item->id );

            $previousOnHand          = $fresh->quantity_on_hand;
            $newOnHand               = $previousOnHand + $delta;
            $fresh->quantity_on_hand = $newOnHand;
            $fresh->save();

            // An audit entry must never roll back the stock change it
            // describes; the savepoint keeps a failed insert from aborting
            // this transaction on PostgreSQL.
            try {
                DB::transaction( static fn () => app( ActivityLogService::class )->recordInventoryAdjustment( $fresh, $delta, $previousOnHand, $newOnHand, $reason ) );
            } catch ( Throwable $exception ) {
                report( $exception );
            }

            doAction( 'ap.ecommerce.inventory.adjusted', $fresh, $delta, $newOnHand );

            $this->fireStockThresholdHooks( $fresh, $previousOnHand, $newOnHand );

            return $fresh;
        } );
    }

    /**
     * Updates a row's stock settings (`track_inventory`, `allow_backorder`,
     * `low_stock_threshold`). Quantities never change here — they go through
     * {@see self::adjust()} so every change is audited. Keys other than the
     * three settings are ignored; a `null` threshold turns low-stock alerts
     * off.
     *
     * @since 1.0.0
     *
     * @param  InventoryItem         $item      Row to update.
     * @param  array<string, mixed>  $settings  Settings to change.
     *
     * @throws InvalidArgumentException When the threshold is negative or not a whole number.
     *
     * @return InventoryItem The refreshed row.
     */
    public function updateSettings( InventoryItem $item, array $settings ): InventoryItem
    {
        $values = array_intersect_key( $settings, array_flip( self::SETTINGS ) );

        if ( array_key_exists( 'low_stock_threshold', $values ) && null !== $values['low_stock_threshold'] ) {
            if ( false === filter_var( $values['low_stock_threshold'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] ) ) {
                throw new InvalidArgumentException( __( 'The low-stock threshold can\'t be negative.' ) );
            }

            $values['low_stock_threshold'] = (int) $values['low_stock_threshold'];
        }

        foreach ( [ 'track_inventory', 'allow_backorder' ] as $flag ) {
            if ( array_key_exists( $flag, $values ) ) {
                $values[ $flag ] = (bool) $values[ $flag ];
            }
        }

        return DB::transaction( function () use ( $item, $values ): InventoryItem {
            $fresh = InventoryItem::query()->lockForUpdate()->findOrFail( $item->id );
            $fresh->fill( $values )->save();

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
     * (default 15) unless overridden via `$expiresAt`. Pass `$expires =
     * false` for a reservation that holds until it is committed or released
     * (an order's: it must outlive the checkout TTL).
     *
     * @since 1.0.0
     *
     * @param  InventoryItem  $item        Row to reserve against.
     * @param  Model          $reservable  Cart or Order the reservation belongs to.
     * @param  int            $quantity    Units to reserve. Must be > 0.
     * @param  Carbon|null    $expiresAt   Optional explicit expiry.
     * @param  bool           $expires     False for a reservation that never expires.
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
        bool $expires = true,
    ): InventoryReservation {
        if ( $quantity < 1 ) {
            throw new InvalidArgumentException( 'Reservation quantity must be at least 1.' );
        }

        $expiresAt = $expires ? ( $expiresAt ?? Carbon::now()->addMinutes( $this->reservationTtlMinutes() ) ) : null;

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
     * Moves every reservation `$from` holds onto `$to`, without an expiry:
     * at placement a cart's checkout reservations become the order's, held
     * until payment commits them or a cancellation releases them (parent
     * plan §7.2).
     *
     * @since 1.0.0
     *
     * @param  Model  $from  Current holder (a Cart).
     * @param  Model  $to    New holder (an Order).
     *
     * @return int Reservations moved.
     */
    public function transferReservations( Model $from, Model $to ): int
    {
        return InventoryReservation::query()
            ->where( 'reservable_type', $from->getMorphClass() )
            ->where( 'reservable_id', $from->getKey() )
            ->update( [
                'reservable_type' => $to->getMorphClass(),
                'reservable_id'   => $to->getKey(),
                'expires_at'      => null,
                'updated_at'      => Carbon::now(),
            ] );
    }

    /**
     * Turns `$reservable`'s reservations into sales: under each inventory
     * row's lock the reservation is deleted, `quantity_reserved` drops by
     * its quantity, and so does `quantity_on_hand` for tracked rows. Called
     * inside the payment capture transaction, so stock leaves the shelf
     * exactly when the money is taken; a refund that restocks puts it back.
     * Idempotent: a second call finds nothing left to commit.
     *
     * Fires `ap.ecommerce.inventory.adjusted` and the low/out-of-stock hooks
     * per row, like {@see self::adjust()}.
     *
     * @since 1.0.0
     *
     * @param  Model  $reservable  Order (or Cart) whose reservations are committed.
     *
     * @return array<int, array{inventory_item_id: int, quantity: int}> What was committed.
     */
    public function commitFor( Model $reservable ): array
    {
        return DB::transaction( function () use ( $reservable ): array {
            $committed    = [];
            $reservations = InventoryReservation::query()
                ->where( 'reservable_type', $reservable->getMorphClass() )
                ->where( 'reservable_id', $reservable->getKey() )
                ->orderBy( 'inventory_item_id' )
                ->orderBy( 'id' )
                ->get();

            foreach ( $reservations as $reservation ) {
                $item = InventoryItem::query()->lockForUpdate()->find( $reservation->inventory_item_id );

                if ( 1 !== InventoryReservation::query()->whereKey( $reservation->getKey() )->delete() || null === $item ) {
                    continue;
                }

                $quantity                = (int) $reservation->quantity;
                $previousOnHand          = (int) $item->quantity_on_hand;
                $item->quantity_reserved = max( 0, (int) $item->quantity_reserved - $quantity );

                if ( $item->track_inventory ) {
                    $item->quantity_on_hand = $previousOnHand - $quantity;
                }

                $item->save();

                if ( $item->track_inventory ) {
                    try {
                        DB::transaction( static fn () => app( ActivityLogService::class )->recordInventoryAdjustment( $item, -$quantity, $previousOnHand, (int) $item->quantity_on_hand, sprintf( 'sale:%s#%s', $reservable->getMorphClass(), $reservable->getKey() ) ) );
                    } catch ( Throwable $exception ) {
                        report( $exception );
                    }

                    doAction( 'ap.ecommerce.inventory.adjusted', $item, -$quantity, (int) $item->quantity_on_hand );
                    $this->fireStockThresholdHooks( $item, $previousOnHand, (int) $item->quantity_on_hand );
                }

                $committed[] = [ 'inventory_item_id' => (int) $item->id, 'quantity' => $quantity ];
            }

            return $committed;
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
                    if ( $this->releaseReservation( $reservation ) ) {
                        ++$released;
                    }
                }
            } );

        return $released;
    }

    /**
     * Releases every reservation held for `$reservable` (a Cart or Order),
     * whether or not it has expired. Used when an order is cancelled.
     *
     * Each release decrements the owning item's `quantity_reserved` (never
     * below zero), deletes the reservation, and fires
     * `ap.ecommerce.inventory.reservationReleased`, the same as
     * {@see self::releaseExpired()}. Runs inside the caller's transaction
     * when there is one.
     *
     * @since 1.0.0
     *
     * @param  Model  $reservable  Cart or Order whose reservations to release.
     *
     * @return array<int, array{inventory_item_id: int, quantity: int}> What was released.
     */
    public function releaseFor( Model $reservable ): array
    {
        return DB::transaction( function () use ( $reservable ): array {
            $released     = [];
            $reservations = InventoryReservation::query()
                ->where( 'reservable_type', $reservable->getMorphClass() )
                ->where( 'reservable_id', $reservable->getKey() )
                ->orderBy( 'id' )
                ->get();

            foreach ( $reservations as $reservation ) {
                if ( $this->releaseReservation( $reservation ) ) {
                    $released[] = [
                        'inventory_item_id' => (int) $reservation->inventory_item_id,
                        'quantity'          => (int) $reservation->quantity,
                    ];
                }
            }

            return $released;
        } );
    }

    /**
     * Releases one reservation. Locks the inventory row first, then deletes
     * the reservation, and only gives the units back when this call deleted
     * it — so the expiry sweep and an order cancel racing on the same
     * reservation release it once, and both take locks in the same order.
     *
     * @since 1.0.0
     *
     * @param  InventoryReservation  $reservation  The reservation.
     *
     * @return bool Whether this call released it.
     */
    protected function releaseReservation( InventoryReservation $reservation ): bool
    {
        return DB::transaction( function () use ( $reservation ): bool {
            $item = InventoryItem::query()->lockForUpdate()->find( $reservation->inventory_item_id );

            if ( 1 !== InventoryReservation::query()->whereKey( $reservation->getKey() )->delete() ) {
                return false;
            }

            if ( null !== $item ) {
                $item->quantity_reserved = max( 0, $item->quantity_reserved - $reservation->quantity );
                $item->save();
            }

            doAction( 'ap.ecommerce.inventory.reservationReleased', $reservation );

            return true;
        } );
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
