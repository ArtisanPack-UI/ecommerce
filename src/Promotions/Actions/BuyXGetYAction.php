<?php

/**
 * BuyXGetYAction.
 *
 * `buy-x-get-y`: for every `buy_quantity` qualifying units bought,
 * `get_quantity` reward units are discounted by `percent` (default 100 =
 * free). Cheapest reward units are discounted first. When the buy and get
 * sets overlap, a unit is never both bought and rewarded: each application
 * takes `buy_quantity` units from the buy set (buy-only units first) and
 * then `get_quantity` of the cheapest remaining reward units. Config:
 *
 * - `buy_product_ids` / `buy_variant_ids` — qualifying items.
 * - `get_product_ids` / `get_variant_ids` — reward items (default: the buy set).
 * - `buy_quantity`, `get_quantity` — positive integers.
 * - `percent` — discount on reward units (default 100).
 * - `max_applications` — optional cap on how many times it applies.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Promotions\Actions;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Support\DiscountLedger;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class BuyXGetYAction extends AbstractPromotionAction
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'buy-x-get-y';

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Buy X get Y' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  DiscountLedger        $ledger  Running ledger.
     * @param  array<string, mixed>  $config  See class docblock.
     *
     * @return void
     */
    public function apply( Cart $cart, DiscountLedger $ledger, array $config ): void
    {
        $buyQuantity = (int) ( $config['buy_quantity'] ?? 0 );
        $getQuantity = (int) ( $config['get_quantity'] ?? 0 );
        $fraction    = $this->fraction( $config['percent'] ?? 100 );

        if ( $buyQuantity < 1 || $getQuantity < 1 || null === $fraction ) {
            return;
        }

        $buyLines = $this->matchingLines( $ledger, $config, 'buy_product_ids', 'buy_variant_ids' );
        $getLines = ( empty( $config['get_product_ids'] ) && empty( $config['get_variant_ids'] ) )
            ? $buyLines
            : $this->matchingLines( $ledger, $config, 'get_product_ids', 'get_variant_ids' );

        if ( $buyLines->isEmpty() || $getLines->isEmpty() ) {
            return;
        }

        $maxApplications = isset( $config['max_applications'] ) && is_numeric( $config['max_applications'] )
            ? max( 0, (int) $config['max_applications'] )
            : PHP_INT_MAX;

        // Remaining units per line, and per-line reward counts.
        $available = [];
        $rewarded  = [];

        foreach ( $buyLines->union( $getLines ) as $id => $line ) {
            $available[ $id ] = $line['quantity'];
            $rewarded[ $id ]  = 0;
        }

        $price    = static fn ( array $line ): int => (int) $line['unit_price']->getAmount();
        $buyOrder = $buyLines
            ->sortBy( [ fn ( array $a, array $b ): int => ( $getLines->has( $a['id'] ) <=> $getLines->has( $b['id'] ) ), fn ( array $a, array $b ): int => $price( $b ) <=> $price( $a ) ] )
            ->keys()
            ->all();
        $getOrder = $getLines->sortBy( $price )->keys()->all();

        for ( $applied = 0; $applied < $maxApplications; $applied++ ) {
            $take = $this->takeUnits( $available, $buyOrder, $buyQuantity );

            if ( null === $take ) {
                break;
            }

            $reward = $this->takeUnits( $available, $getOrder, $getQuantity );

            if ( null === $reward ) {
                break;
            }

            foreach ( $reward as $id => $units ) {
                $rewarded[ $id ] += $units;
            }
        }

        foreach ( $rewarded as $id => $units ) {
            if ( $units > 0 ) {
                $line = $getLines[ $id ];
                $ledger->discountLine( $id, $line['unit_price']->multiply( $units )->multiply( $fraction ) );
            }
        }
    }

    /**
     * Takes `$needed` units from `$available`, walking lines in `$order`.
     * Mutates `$available` only when the full amount can be taken.
     *
     * @since 1.0.0
     *
     * @param  array<int, int>  $available  Remaining units per line (by reference).
     * @param  array<int, int>  $order      Line ids in preference order.
     * @param  int              $needed     Units required.
     *
     * @return array<int, int>|null Units taken per line, or null when there aren't enough.
     */
    private function takeUnits( array &$available, array $order, int $needed ): ?array
    {
        $taken = [];
        $left  = $needed;

        foreach ( $order as $id ) {
            if ( 0 === $left ) {
                break;
            }

            $units = min( $left, $available[ $id ] - ( $taken[ $id ] ?? 0 ) );

            if ( $units > 0 ) {
                $taken[ $id ] = ( $taken[ $id ] ?? 0 ) + $units;
                $left -= $units;
            }
        }

        if ( $left > 0 ) {
            return null;
        }

        foreach ( $taken as $id => $units ) {
            $available[ $id ] -= $units;
        }

        return $taken;
    }
}
