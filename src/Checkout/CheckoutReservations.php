<?php

/**
 * CheckoutReservations.
 *
 * Holds stock for a cart at checkout (parent plan §7.2): every tracked unit
 * a line needs — a bundle's members, a variant's own row — is reserved for
 * the cart through {@see InventoryService::reserve()} with the checkout TTL.
 * The cart's earlier reservations are released first, so calling it again
 * re-holds exactly what the cart holds now.
 *
 * The inventory rows are locked (in id order) before anything is counted,
 * so two carts racing for the last unit can't both get it. When a line asks
 * for more than is left, {@see self::hold()} either reduces it (or removes
 * it when nothing is left) and reports the change — checkout start — or
 * refuses the whole cart — order placement.
 *
 * Call it inside a transaction that holds the cart's row lock.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Checkout;

use ArtisanPackUI\Ecommerce\Exceptions\OrderPlacementException;
use ArtisanPackUI\Ecommerce\Inventory\StockLevels;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Services\InventoryService;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CheckoutReservations
{
    /**
     * @since 1.0.0
     *
     * @param  InventoryService  $inventory  Reservations.
     * @param  StockLevels       $stock      Stock components of a line.
     */
    public function __construct(
        protected InventoryService $inventory,
        protected StockLevels $stock,
    ) {
    }

    /**
     * Reserves the cart's tracked stock.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart    Locked cart.
     * @param  bool  $adjust  Reduce or remove lines that can't be held in full (otherwise refuse).
     *
     * @throws OrderPlacementException When `$adjust` is false and a line can't be held in full.
     *
     * @return array<int, array{item_id: int, product_id: int, requested: int, available: int}> Lines that were reduced (available > 0) or removed (available = 0).
     */
    public function hold( Cart $cart, bool $adjust ): array
    {
        $this->inventory->releaseFor( $cart );

        $lines = $cart->items()->with( [ 'product', 'variant' ] )->orderBy( 'id' )->get();
        $plans = [];
        $ids   = [];

        foreach ( $lines as $line ) {
            $plans[ $line->id ] = $this->plan( $line );

            foreach ( $plans[ $line->id ] as $component ) {
                $ids[] = $component['inventory_item_id'];
            }
        }

        if ( [] === $ids ) {
            return [];
        }

        $rows      = InventoryItem::query()->whereKey( array_unique( $ids ) )->orderBy( 'id' )->lockForUpdate()->get()->keyBy( 'id' );
        $available = $rows->map( static fn ( InventoryItem $row ): int => max( 0, $row->availableQuantity() ) )->all();

        $adjustments = [];

        foreach ( $lines as $line ) {
            $plan = $plans[ $line->id ];

            if ( [] === $plan ) {
                continue;
            }

            $quantity = (int) $line->quantity;
            $possible = $quantity;

            foreach ( $plan as $component ) {
                $possible = min( $possible, intdiv( $available[ $component['inventory_item_id'] ] ?? 0, $component['per_unit'] ) );
            }

            if ( $possible < $quantity ) {
                if ( ! $adjust ) {
                    throw OrderPlacementException::insufficientStock();
                }

                $adjustments[] = [ 'item_id' => (int) $line->id, 'product_id' => (int) $line->product_id, 'requested' => $quantity, 'available' => $possible ];

                if ( $possible < 1 ) {
                    $line->delete();

                    continue;
                }

                $line->forceFill( [
                    'quantity'             => $possible,
                    'line_subtotal_amount' => (int) $line->unit_price_amount * $possible,
                    'line_total_amount'    => (int) $line->unit_price_amount * $possible,
                ] )->save();

                $quantity = $possible;
            }

            foreach ( $plan as $component ) {
                $units = $component['per_unit'] * $quantity;

                $available[ $component['inventory_item_id'] ] -= $units;
                $this->inventory->reserve( $rows[ $component['inventory_item_id'] ], $cart, $units );
            }
        }

        return $adjustments;
    }

    /**
     * The tracked inventory rows one unit of `$line` draws on, with units
     * per line unit.
     *
     * @since 1.0.0
     *
     * @param  CartItem  $line  Line.
     *
     * @return array<int, array{inventory_item_id: int, per_unit: int}>
     */
    protected function plan( CartItem $line ): array
    {
        if ( null === $line->product ) {
            return [];
        }

        $plan = [];

        foreach ( $this->stock->components( $line->product, $line->variant, 1 ) as $component ) {
            $row = $this->stock->itemFor( $component['stockable'] );

            if ( null === $row || ! $row->track_inventory || $row->allow_backorder ) {
                continue;
            }

            $plan[ $row->id ] = [
                'inventory_item_id' => (int) $row->id,
                'per_unit'          => ( $plan[ $row->id ]['per_unit'] ?? 0 ) + max( 1, (int) $component['quantity'] ),
            ];
        }

        return array_values( $plan );
    }
}
